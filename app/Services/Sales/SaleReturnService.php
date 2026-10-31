<?php

namespace App\Services\Sales;

use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItemBatch;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\DocumentNumberer;
use App\Support\PharmacySettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Retours clients (POS-09, QA-12). La vente d'origine n'est jamais modifiée : le retour est
 * une écriture compensatoire avec son numéro d'avoir, son remboursement et ses mouvements de stock.
 *
 * Un médicament retourné ne réintègre pas automatiquement le stock vendable : par défaut il
 * part en quarantaine ; la remise en rayon exige une habilitation pharmaceutique.
 *
 * Données attendues :
 *   idempotency_key    string
 *   payment_method_id  mode de remboursement
 *   reason             motif obligatoire
 *   items[]            sale_item_batch_id, quantity (unités de base), disposition (quarantine|restock_approved|destroy)
 */
class SaleReturnService
{
    public function __construct(
        private readonly FefoAllocator $allocator,
        private readonly DocumentNumberer $numberer,
        private readonly CashSessionService $cash,
    ) {}

    /**
     * Quantités déjà retournées par affectation de lot de la vente.
     *
     * @return array<string, int> sale_item_batch_id => unités de base
     */
    public function returnedQuantities(Sale $sale): array
    {
        return SaleReturnItem::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_returns.sale_id', $sale->id)
            ->groupBy('sale_item_batch_id')
            ->selectRaw('sale_item_batch_id, SUM(quantity_base_units) as qty')
            ->pluck('qty', 'sale_item_batch_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    public function process(User $user, CashSession $session, Sale $sale, array $data): SaleReturn
    {
        $key = $data['idempotency_key'] ?? throw new SaleException('missing_idempotency_key', "Clé d'idempotence manquante.");

        if ($existing = SaleReturn::where('idempotency_key', $key)->first()) {
            return $existing->load('items');
        }

        try {
            return DB::transaction(fn () => $this->doProcess($user, $session, $sale, $data, $key));
        } catch (UniqueConstraintViolationException $e) {
            return SaleReturn::where('idempotency_key', $key)->firstOrFail()->load('items');
        }
    }

    private function doProcess(User $user, CashSession $session, Sale $sale, array $data, string $key): SaleReturn
    {
        $session = CashSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
        if ($session->status !== 'open') {
            throw new SaleException('cash_session_closed', 'La session de caisse est fermée.');
        }
        if ($user->pharmacy_id !== $sale->pharmacy_id || $session->pharmacy_id !== $sale->pharmacy_id || ! $user->hasPermission('sale.refund')) {
            throw new SaleException('forbidden', "Vous n'êtes pas autorisé à effectuer un retour.");
        }

        // Verrou sur la vente : deux retours simultanés ne peuvent pas dépasser la quantité vendue
        $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
        if ($sale->status !== 'finalized') {
            throw new SaleException('sale_not_finalized', 'Seule une vente encaissée peut faire l\'objet d\'un retour.');
        }

        $reason = trim($data['reason'] ?? '');
        if ($reason === '') {
            throw new SaleException('reason_required', 'Le motif du retour est obligatoire.');
        }

        $method = PaymentMethod::where('pharmacy_id', $sale->pharmacy_id)->where('is_active', true)
            ->find($data['payment_method_id'] ?? null)
            ?? throw new SaleException('invalid_payment_method', 'Mode de remboursement invalide.');

        $lines = $this->buildLines($user, $sale, $data['items'] ?? []);
        $rawTotal = array_sum(array_column($lines, 'amount'));
        $step = (int) round((float) PharmacySettings::get($sale->pharmacy_id, 'sale.rounding_step', 1) * 100);
        $total = $step > 0 ? (int) (round($rawTotal / $step) * $step) : $rawTotal;

        if ($method->is_cash && $total > $this->cash->summary($session)['expected_cash']) {
            throw new SaleException('insufficient_cash', 'Espèces insuffisantes en caisse pour ce remboursement.');
        }

        $return = SaleReturn::create([
            'pharmacy_id' => $sale->pharmacy_id,
            'sale_id' => $sale->id,
            'cash_session_id' => $session->id,
            'idempotency_key' => $key,
            'credit_note_number' => $this->numberer->next($session->register, 'credit_note', 'AV-'),
            'refund_mode' => 'cash_refund',
            'total' => $this->money($total),
            'reason' => $reason,
            'user_id' => $user->id,
            'approved_by' => $user->id,
        ]);

        foreach ($lines as $line) {
            $return->items()->create([
                'sale_item_batch_id' => $line['allocation']->id,
                'quantity_base_units' => $line['quantity'],
                'amount' => $this->money($line['amount']),
                'disposition' => $line['disposition'],
            ]);
            $this->moveStock($return, $user, $line);
        }

        // Remboursement : paiement négatif sur la session courante
        $sale->payments()->create([
            'pharmacy_id' => $sale->pharmacy_id,
            'cash_session_id' => $session->id,
            'payment_method_id' => $method->id,
            'idempotency_key' => "$key:refund",
            'amount' => $this->money(-$total),
            'reference' => $data['reference'] ?? null,
            'kind' => 'refund',
            'user_id' => $user->id,
        ]);

        AuditLog::create([
            'pharmacy_id' => $sale->pharmacy_id,
            'user_id' => $user->id,
            'action' => 'sale.refund',
            'auditable_type' => 'sale_return',
            'auditable_id' => $return->id,
            'reason' => $reason,
            'new_values' => [
                'ticket_number' => $sale->ticket_number,
                'credit_note_number' => $return->credit_note_number,
                'total' => $return->total,
                'dispositions' => array_count_values(array_column($lines, 'disposition')),
            ],
        ]);

        OutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'pharmacy_id' => $sale->pharmacy_id,
            'aggregate_type' => 'sale_return',
            'aggregate_id' => $return->id,
            'event_type' => 'sale_return.processed',
            'sequence' => 1,
            'payload' => ['sale_id' => $sale->id, 'credit_note_number' => $return->credit_note_number, 'total' => $return->total],
            'occurred_at' => now(),
        ]);

        return $return->load('items');
    }

    private function buildLines(User $user, Sale $sale, array $items): array
    {
        $items = collect($items)->filter(fn ($i) => (int) ($i['quantity'] ?? 0) > 0)->values();
        if ($items->isEmpty()) {
            throw new SaleException('empty_return', 'Aucun article à retourner.');
        }

        $allocations = SaleItemBatch::with(['saleItem', 'batch', 'location'])
            ->whereIn('id', $items->pluck('sale_item_batch_id'))
            ->get()
            ->keyBy('id');
        $returned = $this->returnedQuantities($sale);
        $lines = [];

        foreach ($items as $i => $item) {
            $allocation = $allocations->get($item['sale_item_batch_id'] ?? null);
            if (! $allocation || $allocation->saleItem->sale_id !== $sale->id) {
                throw new SaleException('unknown_line', "Article introuvable sur ce ticket.", ['line' => $i + 1]);
            }

            $quantity = (int) $item['quantity'];
            $remaining = $allocation->quantity_base_units - ($returned[$allocation->id] ?? 0);
            if ($quantity > $remaining) {
                throw new SaleException('quantity_exceeds_sold', "Quantité retournée supérieure à la quantité vendue restante ($remaining).", ['line' => $i + 1]);
            }

            $disposition = $item['disposition'] ?? 'quarantine';
            if (! in_array($disposition, ['quarantine', 'restock_approved', 'destroy'], true)) {
                throw new SaleException('invalid_disposition', 'Destination du retour invalide.', ['line' => $i + 1]);
            }
            if ($disposition !== 'quarantine' && ! $user->hasPermission('batch.quarantine')) {
                throw new SaleException('disposition_forbidden', 'Seul un pharmacien habilité peut remettre en rayon ou détruire un retour.', ['line' => $i + 1]);
            }
            if ($disposition === 'restock_approved') {
                $balance = new StockBalance(['location_id' => $allocation->location_id]);
                $balance->setRelation('batch', $allocation->batch)->setRelation('location', $allocation->location);
                if ($reason = $this->allocator->refusalReason($balance)) {
                    throw new SaleException('restock_not_allowed', "Remise en rayon impossible : lot non vendable ($reason).", ['line' => $i + 1]);
                }
            }

            // Montant au prorata de la ligne encaissée (remise comprise)
            $saleItem = $allocation->saleItem;
            $amount = (int) round((float) $saleItem->line_total * 100 * $quantity / $saleItem->quantity_base_units);

            $lines[] = compact('allocation', 'quantity', 'disposition', 'amount');
        }

        return $lines;
    }

    private function moveStock(SaleReturn $return, User $user, array $line): void
    {
        $allocation = $line['allocation'];
        $location = $line['disposition'] === 'restock_approved'
            ? $allocation->location
            : $this->quarantineLocation($return->pharmacy_id);

        $balance = StockBalance::where('batch_id', $allocation->batch_id)->where('location_id', $location->id)->lockForUpdate()->first()
            ?? StockBalance::create([
                'pharmacy_id' => $return->pharmacy_id,
                'presentation_id' => $allocation->batch->presentation_id,
                'batch_id' => $allocation->batch_id,
                'location_id' => $location->id,
                'quantity' => 0,
            ]);

        $this->movement($return, $user, $balance, 'sale_return', $line['quantity'], $allocation->unit_cost);

        if ($line['disposition'] === 'destroy') {
            $this->movement($return, $user, $balance, 'destruction', -$line['quantity'], $allocation->unit_cost);
        }
    }

    private function movement(SaleReturn $return, User $user, StockBalance $balance, string $type, int $quantity, string $unitCost): void
    {
        $balance->quantity += $quantity;
        $balance->version++;
        $balance->save();

        StockMovement::create([
            'pharmacy_id' => $return->pharmacy_id,
            'presentation_id' => $balance->presentation_id,
            'batch_id' => $balance->batch_id,
            'location_id' => $balance->location_id,
            'type' => $type,
            'quantity' => $quantity,
            'balance_after' => $balance->quantity,
            'unit_cost' => $unitCost,
            'source_type' => 'sale_return',
            'source_id' => $return->id,
            'reason' => $return->reason,
            'user_id' => $user->id,
            'occurred_at' => now(),
        ]);
    }

    private function quarantineLocation(string $pharmacyId): Location
    {
        return Location::where('pharmacy_id', $pharmacyId)->where('type', 'quarantine')->where('is_active', true)->first()
            ?? throw new SaleException('no_quarantine_location', "Aucun emplacement de quarantaine n'est configuré.");
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
