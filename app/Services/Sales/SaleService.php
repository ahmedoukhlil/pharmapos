<?php

namespace App\Services\Sales;

use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\OutboxEvent;
use App\Models\PaymentMethod;
use App\Models\Presentation;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\DocumentNumberer;
use App\Support\PharmacySettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Validation atomique d'une vente : lignes, affectation des lots, sorties de stock,
 * paiements, numéro de ticket et événement de synchronisation (PRD §7, POS-11, POS-13).
 *
 * Les montants sont calculés en centimes entiers pour éviter les erreurs d'arrondi flottant.
 *
 * Données attendues :
 *   idempotency_key  string  fournie par le client, rejouable sans doublon
 *   items[]          presentation_id, quantity, sale_unit (pack|unit), batch_id?, discount_amount?
 *   payments[]       payment_method_id, amount, reference?
 */
class SaleService
{
    public function __construct(
        private readonly FefoAllocator $allocator,
        private readonly DocumentNumberer $numberer,
    ) {}

    public function finalize(User $user, CashSession $session, array $data): Sale
    {
        $key = $data['idempotency_key'] ?? throw new SaleException('missing_idempotency_key', "Clé d'idempotence manquante.");

        if ($existing = Sale::where('idempotency_key', $key)->first()) {
            return $this->replay($existing, $session);
        }

        try {
            return DB::transaction(fn () => $this->doFinalize($user, $session, $data, $key));
        } catch (UniqueConstraintViolationException $e) {
            // Retransmission concurrente : l'autre requête a gagné, on renvoie sa vente
            $existing = Sale::where('idempotency_key', $key)->first();
            if (! $existing) {
                throw $e;
            }

            return $this->replay($existing, $session);
        }
    }

    private function replay(Sale $sale, CashSession $session): Sale
    {
        if ($sale->cash_session_id !== $session->id) {
            throw new SaleException('idempotency_conflict', "Clé d'idempotence déjà utilisée par une autre session.");
        }

        return $sale->load(['items.batches', 'payments']);
    }

    private function doFinalize(User $user, CashSession $session, array $data, string $key): Sale
    {
        $session = $this->lockOpenSession($session, $user, 'sale.create');
        // Reprise d'une vente en attente : ses réservations sont rendues avant la réaffectation
        $held = $this->takeOverHeld($data['held_sale_id'] ?? null, $session);

        [$lines, $rawTotal, $total] = $this->prepareLines($user, $session, $data['items'] ?? []);
        [$payments, $paid, $change] = $this->checkPayments($session->pharmacy_id, $data['payments'] ?? [], $total);
        $pharmacyId = $session->pharmacy_id;

        $attributes = [
            'pharmacy_id' => $pharmacyId,
            'register_id' => $session->register_id,
            'cash_session_id' => $session->id,
            'user_id' => $user->id,
            'idempotency_key' => $key,
            'ticket_number' => $this->numberer->next($session->register, 'ticket'),
            'status' => 'finalized',
            'paid_total' => $this->money($paid),
            'change_given' => $this->money($change),
            'finalized_at' => now(),
        ] + $this->totalsAttributes($lines, $rawTotal, $total);

        $sale = $held ? tap($held)->update($attributes) : Sale::create($attributes);

        foreach ($lines as $line) {
            $this->persistLine($sale, $user, $line);
        }

        foreach ($payments as $i => $payment) {
            $sale->payments()->create([
                'pharmacy_id' => $pharmacyId,
                'cash_session_id' => $session->id,
                'payment_method_id' => $payment['method']->id,
                'idempotency_key' => "$key:p".($i + 1),
                'amount' => $this->money($payment['amount']),
                'reference' => $payment['reference'],
                'kind' => 'payment',
                'user_id' => $user->id,
            ]);
        }

        OutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'pharmacy_id' => $pharmacyId,
            'aggregate_type' => 'sale',
            'aggregate_id' => $sale->id,
            'event_type' => 'sale.finalized',
            'sequence' => 1,
            'payload' => [
                'ticket_number' => $sale->ticket_number,
                'total' => $sale->total,
                'register_id' => $sale->register_id,
                'items' => count($lines),
            ],
            'occurred_at' => $sale->finalized_at,
        ]);

        return $sale->load(['items.batches', 'payments']);
    }

    /**
     * Met une vente en attente (POS-05, POS-15) : lignes enregistrées et stock FEFO réservé
     * jusqu'à expiration ; aucune sortie de stock ni paiement. Une vente reprise puis remise
     * en attente conserve le même enregistrement.
     */
    public function hold(User $user, CashSession $session, array $data): Sale
    {
        return DB::transaction(function () use ($user, $session, $data) {
            $session = $this->lockOpenSession($session, $user, 'sale.hold');
            $held = $this->takeOverHeld($data['held_sale_id'] ?? null, $session);

            [$lines, $rawTotal, $total] = $this->prepareLines($user, $session, $data['items'] ?? []);

            $attributes = [
                'pharmacy_id' => $session->pharmacy_id,
                'register_id' => $session->register_id,
                'cash_session_id' => $session->id,
                'user_id' => $user->id,
                'idempotency_key' => $held?->idempotency_key ?? (string) Str::uuid(),
                'status' => 'on_hold',
                'held_at' => now(),
            ] + $this->totalsAttributes($lines, $rawTotal, $total);

            $sale = $held ? tap($held)->update($attributes) : Sale::create($attributes);
            $minutes = (int) PharmacySettings::get($session->pharmacy_id, 'sale.hold_reservation_minutes', 30);

            foreach ($lines as $line) {
                $sale->items()->create($this->itemAttributes($line));

                foreach ($line['allocations'] as ['balance' => $balance, 'quantity' => $quantity]) {
                    $sale->reservations()->create([
                        'stock_balance_id' => $balance->id,
                        'quantity' => $quantity,
                        'expires_at' => now()->addMinutes($minutes),
                    ]);
                    $balance->reserved_quantity += $quantity;
                    $balance->version++;
                    $balance->save();
                }
            }

            return $sale->load('items');
        });
    }

    /** Annule une vente en attente et libère son stock réservé. */
    public function cancelHeld(User $user, Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($user, $sale, $reason) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'on_hold') {
                throw new SaleException('not_on_hold', "Cette vente n'est pas en attente.");
            }
            if ($user->pharmacy_id !== $sale->pharmacy_id || ! $user->hasPermission('sale.hold')) {
                throw new SaleException('forbidden', "Vous n'êtes pas autorisé à annuler cette vente.");
            }

            $this->releaseReservations($sale);
            $sale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
            ]);

            return $sale;
        });
    }

    /**
     * Libère les réservations expirées ; les ventes restent en attente et seront
     * réaffectées au stock disponible lors de leur reprise. Retourne le nombre libéré.
     */
    public function releaseExpiredReservations(): int
    {
        $count = 0;
        StockReservation::whereNull('released_at')->where('expires_at', '<', now())
            ->orderBy('stock_balance_id')
            ->each(function (StockReservation $reservation) use (&$count) {
                DB::transaction(function () use ($reservation, &$count) {
                    $fresh = StockReservation::whereKey($reservation->id)->whereNull('released_at')->lockForUpdate()->first();
                    if ($fresh) {
                        $this->release($fresh);
                        $count++;
                    }
                });
            });

        return $count;
    }

    /** Lignes en attente sous la forme attendue par l'écran de caisse pour reprendre la vente. */
    public function heldItems(Sale $sale): array
    {
        return $sale->items()->orderBy('line_no')->get()->map(fn ($item) => [
            'presentation_id' => $item->presentation_id,
            'quantity' => $item->quantity,
            'sale_unit' => $item->sale_unit,
            'batch_id' => null,
            'discount_amount' => (string) $item->discount_amount,
        ])->all();
    }

    private function lockOpenSession(CashSession $session, User $user, string $permission): CashSession
    {
        $session = CashSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
        if ($session->status !== 'open') {
            throw new SaleException('cash_session_closed', 'La session de caisse est fermée.');
        }
        if ($user->pharmacy_id !== $session->pharmacy_id || ! $user->hasPermission($permission)) {
            throw new SaleException('forbidden', "Vous n'êtes pas autorisé à encaisser sur cette caisse.");
        }

        return $session;
    }

    /**
     * Verrouille une vente en attente de la même officine, libère ses réservations et
     * supprime ses lignes provisoires (jamais finalisées) pour la reconstruire.
     */
    private function takeOverHeld(?string $saleId, CashSession $session): ?Sale
    {
        if (! $saleId) {
            return null;
        }
        $sale = Sale::whereKey($saleId)->lockForUpdate()->first();
        if (! $sale || $sale->pharmacy_id !== $session->pharmacy_id || $sale->status !== 'on_hold') {
            throw new SaleException('held_sale_unavailable', "La vente en attente n'est plus disponible.");
        }

        $this->releaseReservations($sale);
        $sale->items()->delete();

        return $sale;
    }

    private function releaseReservations(Sale $sale): void
    {
        $sale->reservations()->whereNull('released_at')->orderBy('stock_balance_id')->lockForUpdate()->get()
            ->each(fn (StockReservation $r) => $this->release($r));
    }

    private function release(StockReservation $reservation): void
    {
        StockBalance::whereKey($reservation->stock_balance_id)->update([
            'reserved_quantity' => DB::raw('GREATEST(reserved_quantity - '.(int) $reservation->quantity.', 0)'),
            'version' => DB::raw('version + 1'),
        ]);
        $reservation->update(['released_at' => now()]);
    }

    /**
     * Construit les lignes (prix, remises, affectation FEFO) sous verrou des soldes.
     *
     * @return array{0: array, 1: int, 2: int} lignes, total brut, total arrondi (centimes)
     */
    private function prepareLines(User $user, CashSession $session, array $items): array
    {
        $pharmacyId = $session->pharmacy_id;
        $items = collect($items);
        if ($items->isEmpty()) {
            throw new SaleException('empty_sale', 'La vente ne contient aucune ligne.');
        }

        $presentations = Presentation::with('medicine.category')
            ->where('pharmacy_id', $pharmacyId)
            ->whereIn('id', $items->pluck('presentation_id')->unique())
            ->get()
            ->keyBy('id');

        // Verrouille tous les soldes concernés en une fois, dans un ordre déterministe
        $balances = $this->allocator
            ->sellableBalances($pharmacyId, $presentations->keys()->all(), lock: true)
            ->groupBy('presentation_id');

        $maxDiscountPercent = $this->maxDiscountPercent($user, $pharmacyId);
        $lines = [];
        foreach ($items->values() as $i => $item) {
            $lines[] = $this->buildLine($user, $i + 1, $item, $presentations, $balances, $maxDiscountPercent);
        }

        $rawTotal = array_sum(array_column($lines, 'line_total'));

        return [$lines, $rawTotal, $this->roundTotal($rawTotal, $pharmacyId)];
    }

    private function totalsAttributes(array $lines, int $rawTotal, int $total): array
    {
        return [
            'subtotal' => $this->money(array_sum(array_column($lines, 'gross'))),
            'discount_total' => $this->money(array_sum(array_column($lines, 'discount'))),
            'tax_total' => $this->money(array_sum(array_column($lines, 'tax'))),
            'rounding_adjustment' => $this->money($total - $rawTotal),
            'total' => $this->money($total),
        ];
    }

    private function itemAttributes(array $line): array
    {
        return [
            'presentation_id' => $line['presentation']->id,
            'line_no' => $line['line_no'],
            'sale_unit' => $line['sale_unit'],
            'quantity' => $line['quantity'],
            'quantity_base_units' => $line['base_units'],
            'unit_price' => $line['unit_price'],
            'discount_amount' => $this->money($line['discount']),
            'tax_rate' => $line['tax_rate'],
            'line_total' => $this->money($line['line_total']),
        ];
    }

    private function buildLine(User $user, int $lineNo, array $item, Collection $presentations, Collection $balances, float $maxDiscountPercent): array
    {
        $presentation = $presentations->get($item['presentation_id'] ?? null)
            ?? throw new SaleException('unknown_product', 'Produit inconnu.', ['line' => $lineNo]);

        if ($presentation->status !== 'active' || $presentation->medicine->status !== 'active') {
            throw new SaleException('product_not_sellable', "{$presentation->label} n'est pas en vente.", ['line' => $lineNo]);
        }

        $quantity = (int) ($item['quantity'] ?? 0);
        if ($quantity <= 0) {
            throw new SaleException('invalid_quantity', 'Quantité invalide.', ['line' => $lineNo]);
        }

        $unit = $item['sale_unit'] ?? 'pack';
        if ($unit === 'pack') {
            $baseUnits = $quantity * $presentation->units_per_pack;
            $unitPrice = (string) $presentation->sale_price;
        } elseif ($unit === 'unit' && $presentation->allow_split_sale && $presentation->unit_sale_price !== null) {
            $baseUnits = $quantity;
            $unitPrice = (string) $presentation->unit_sale_price;
        } else {
            throw new SaleException('split_not_allowed', "Vente à l'unité non autorisée pour {$presentation->label}.", ['line' => $lineNo]);
        }

        // Produit importé sans prix de vente : on ne le vend pas à 0
        if ((float) $unitPrice <= 0) {
            throw new SaleException('missing_price', "Prix de vente non renseigné pour {$presentation->medicine?->brand_name} {$presentation->label} : complétez-le dans le catalogue.", ['line' => $lineNo]);
        }

        $candidates = $balances->get($presentation->id, collect());
        $fefoBatchId = $candidates->first()?->batch_id;
        $overridden = false;

        if (! empty($item['batch_id'])) {
            $chosen = $candidates->where('batch_id', $item['batch_id']);
            if ($chosen->isEmpty()) {
                throw new SaleException('batch_not_sellable', 'Lot non vendable (expiré, en quarantaine, rappelé ou absent).', [
                    'line' => $lineNo, 'batch_id' => $item['batch_id'],
                ]);
            }
            $overridden = $item['batch_id'] !== $fefoBatchId;
            if ($overridden && ! $user->hasPermission('sale.fefo_override')) {
                throw new SaleException('fefo_override_forbidden', 'Choix d\'un lot hors FEFO non autorisé.', ['line' => $lineNo]);
            }
            $candidates = $chosen;
        }

        $allocations = $this->allocator->allocate($candidates, $baseUnits);

        $gross = (int) round($quantity * (float) $unitPrice * 100);
        $discount = $this->cents($item['discount_amount'] ?? 0);
        if ($discount < 0 || $discount > $gross) {
            throw new SaleException('invalid_discount', 'Remise invalide.', ['line' => $lineNo]);
        }
        if ($discount > 0) {
            if (! $user->hasPermission('sale.discount')) {
                throw new SaleException('discount_forbidden', 'Remise non autorisée.', ['line' => $lineNo]);
            }
            if ($discount > $gross * $maxDiscountPercent / 100) {
                throw new SaleException('discount_above_limit', 'Remise supérieure au plafond autorisé.', ['line' => $lineNo]);
            }
        }

        $lineTotal = $gross - $discount;
        $taxRate = (float) ($presentation->medicine->category?->tax_rate ?? 0);

        return [
            'line_no' => $lineNo,
            'presentation' => $presentation,
            'sale_unit' => $unit,
            'quantity' => $quantity,
            'base_units' => $baseUnits,
            'unit_price' => $unitPrice,
            'gross' => $gross,
            'discount' => $discount,
            'tax_rate' => $taxRate,
            // Prix TTC : part de taxe incluse
            'tax' => (int) round($lineTotal * $taxRate / (100 + $taxRate)),
            'line_total' => $lineTotal,
            'allocations' => $allocations,
            'fefo_overridden' => $overridden,
        ];
    }

    private function persistLine(Sale $sale, User $user, array $line): void
    {
        $saleItem = $sale->items()->create($this->itemAttributes($line));

        foreach ($line['allocations'] as ['balance' => $balance, 'quantity' => $quantity]) {
            $saleItem->batches()->create([
                'batch_id' => $balance->batch_id,
                'location_id' => $balance->location_id,
                'quantity_base_units' => $quantity,
                'unit_cost' => $balance->batch->unit_cost,
                'fefo_overridden' => $line['fefo_overridden'],
                'override_approved_by' => $line['fefo_overridden'] ? $user->id : null,
            ]);

            $balance->quantity -= $quantity;
            $balance->version++;
            if ($balance->quantity < 0) {
                throw new SaleException('negative_stock', 'Le stock deviendrait négatif.');
            }
            $balance->save();

            StockMovement::create([
                'pharmacy_id' => $sale->pharmacy_id,
                'presentation_id' => $balance->presentation_id,
                'batch_id' => $balance->batch_id,
                'location_id' => $balance->location_id,
                'type' => 'sale',
                'quantity' => -$quantity,
                'balance_after' => $balance->quantity,
                'unit_cost' => $balance->batch->unit_cost,
                'source_type' => 'sale',
                'source_id' => $sale->id,
                'user_id' => $user->id,
                'occurred_at' => $sale->finalized_at,
            ]);
        }

        if ($line['fefo_overridden'] || $line['discount'] > 0) {
            AuditLog::create([
                'pharmacy_id' => $sale->pharmacy_id,
                'user_id' => $user->id,
                'action' => $line['fefo_overridden'] ? 'sale.fefo_override' : 'sale.discount',
                'auditable_type' => 'sale_item',
                'auditable_id' => $saleItem->id,
                'new_values' => [
                    'ticket_number' => $sale->ticket_number,
                    'discount' => $saleItem->discount_amount,
                    'batches' => collect($line['allocations'])->map(fn ($a) => $a['balance']->batch->lot_number)->all(),
                ],
            ]);
        }
    }

    /**
     * @return array{0: array, 1: int, 2: int} paiements validés, total payé, rendu
     */
    private function checkPayments(string $pharmacyId, array $input, int $total): array
    {
        $methods = PaymentMethod::where('pharmacy_id', $pharmacyId)->where('is_active', true)->get()->keyBy('id');
        $payments = [];
        $paid = 0;
        $cash = 0;

        foreach ($input as $payment) {
            $method = $methods->get($payment['payment_method_id'] ?? null)
                ?? throw new SaleException('invalid_payment_method', 'Mode de paiement invalide ou inactif.');
            $amount = $this->cents($payment['amount'] ?? 0);
            if ($amount <= 0) {
                throw new SaleException('invalid_payment_amount', 'Montant de paiement invalide.');
            }
            $reference = $payment['reference'] ?? null;
            if ($reference !== null && ! $method->reference_allowed) {
                throw new SaleException('reference_not_allowed', "Référence non applicable à {$method->name}.");
            }

            $payments[] = ['method' => $method, 'amount' => $amount, 'reference' => $reference];
            $paid += $amount;
            $cash += $method->is_cash ? $amount : 0;
        }

        if ($paid < $total) {
            throw new SaleException('insufficient_payment', 'Paiement insuffisant.', ['missing' => $this->money($total - $paid)]);
        }

        // Seules les espèces peuvent dépasser le total ; le rendu ne peut excéder les espèces reçues
        $change = $paid - $total;
        if ($change > $cash) {
            throw new SaleException('overpayment_non_cash', 'Un paiement mobile ou carte ne peut excéder le reste dû.');
        }

        return [$payments, $paid, $change];
    }

    private function roundTotal(int $cents, string $pharmacyId): int
    {
        $step = (int) round((float) PharmacySettings::get($pharmacyId, 'sale.rounding_step', 1) * 100);

        return $step > 0 ? (int) (round($cents / $step) * $step) : $cents;
    }

    private function maxDiscountPercent(User $user, string $pharmacyId): float
    {
        return (float) $user->roleAssignments()
            ->where('pharmacy_id', $pharmacyId)
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->max('roles.max_discount_percent');
    }

    private function cents(int|float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
