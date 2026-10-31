<?php

namespace App\Services\Purchasing;

use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Presentation;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Alerts\AlertService;
use App\Services\DocumentNumberer;
use Illuminate\Support\Facades\DB;

/**
 * ACH-02 : commandes fournisseurs. Brouillon modifiable → envoyée → reçue partiellement / totalement.
 * Les quantités reçues sont reportées par GoodsReceiptService à la validation de chaque réception.
 *
 * Lignes : presentation_id, quantity_packs, unit_price (par conditionnement).
 */
class PurchaseOrderService
{
    public function __construct(private readonly DocumentNumberer $numberer) {}

    public function save(User $user, array $data, ?PurchaseOrder $order = null): PurchaseOrder
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $data, $order) {
            $pharmacyId = $user->pharmacy_id;
            $supplier = Supplier::where('pharmacy_id', $pharmacyId)->where('is_active', true)->find($data['supplier_id'] ?? null)
                ?? throw new PurchaseOrderException('invalid_supplier', 'Fournisseur invalide.');

            $lines = collect($data['lines'] ?? [])->values();
            if ($lines->isEmpty()) {
                throw new PurchaseOrderException('empty_order', 'La commande ne contient aucune ligne.');
            }
            $presentations = Presentation::where('pharmacy_id', $pharmacyId)->whereIn('id', $lines->pluck('presentation_id'))->get()->keyBy('id');

            $attributes = [
                'supplier_id' => $supplier->id,
                'expected_at' => ($data['expected_at'] ?? null) ?: null,
                'notes' => ($data['notes'] ?? null) ?: null,
            ];

            if ($order) {
                $order = $this->lockDraft($order, $pharmacyId);
                $order->update($attributes);
                $order->lines()->delete();
            } else {
                $order = PurchaseOrder::create($attributes + [
                    'pharmacy_id' => $pharmacyId,
                    'number' => $this->numberer->nextForPharmacy($pharmacyId, 'purchase_order', 'BC'),
                    'status' => 'draft',
                    'created_by' => $user->id,
                ]);
            }

            $totalCents = 0;
            foreach ($lines as $i => $line) {
                $presentation = $presentations->get($line['presentation_id'] ?? null)
                    ?? throw new PurchaseOrderException('unknown_product', 'Ligne '.($i + 1).' : produit inconnu.');
                $packs = (int) ($line['quantity_packs'] ?? 0);
                if ($packs <= 0) {
                    throw new PurchaseOrderException('invalid_quantity', 'Ligne '.($i + 1).' : quantité invalide.');
                }
                if (! is_numeric($line['unit_price'] ?? null) || (float) $line['unit_price'] < 0) {
                    throw new PurchaseOrderException('invalid_price', 'Ligne '.($i + 1).' : prix invalide.');
                }
                $priceCents = (int) round((float) $line['unit_price'] * 100);
                $totalCents += $priceCents * $packs;

                $order->lines()->create([
                    'presentation_id' => $presentation->id,
                    'quantity_packs' => $packs,
                    'unit_price' => $priceCents / 100,
                ]);
            }
            $order->update(['total_amount' => $totalCents / 100]);

            return $order->load('lines');
        });
    }

    /** Proposition de lignes depuis les alertes de stock bas (quantité de réappro en boîtes, prix d'achat courant). */
    public function suggestions(User $user): array
    {
        return app(AlertService::class)->lowStock($user->pharmacy_id)->map(fn (Presentation $p) => [
            'presentation_id' => $p->id,
            'quantity_packs' => (string) max(1, intdiv(max($p->reorder_quantity, $p->reorder_threshold - (int) $p->sellable_quantity), $p->units_per_pack)),
            'unit_price' => (string) $p->purchase_price,
        ])->values()->all();
    }

    public function send(User $user, PurchaseOrder $order): PurchaseOrder
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $order) {
            $order = $this->lockDraft($order, $user->pharmacy_id);
            if (! $order->lines()->exists()) {
                throw new PurchaseOrderException('empty_order', 'La commande ne contient aucune ligne.');
            }
            $order->update(['status' => 'sent', 'ordered_at' => now()->toDateString()]);
            $this->audit($user, $order, 'purchase_order.sent');

            return $order;
        });
    }

    /** Annulation d'un brouillon ou d'une commande envoyée sans réception validée. */
    public function cancel(User $user, PurchaseOrder $order): PurchaseOrder
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $order) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->pharmacy_id === $user->pharmacy_id, 404);
            if (! in_array($order->status, ['draft', 'sent'], true)) {
                throw new PurchaseOrderException('not_cancellable', 'Commande déjà (partiellement) reçue : elle ne peut plus être annulée.');
            }
            GoodsReceipt::where('purchase_order_id', $order->id)->where('status', 'draft')->update(['status' => 'cancelled']);
            $order->update(['status' => 'cancelled']);
            $this->audit($user, $order, 'purchase_order.cancelled');

            return $order;
        });
    }

    /**
     * Réception pré-remplie avec le reliquat de chaque ligne ; lot et péremption restent à saisir.
     * Réutilise le brouillon en cours s'il existe.
     */
    public function startReceipt(User $user, PurchaseOrder $order): GoodsReceipt
    {
        $this->authorize($user);
        abort_unless($order->pharmacy_id === $user->pharmacy_id, 404);
        if (! in_array($order->status, ['sent', 'partially_received'], true)) {
            throw new PurchaseOrderException('not_receivable', 'Seule une commande envoyée peut être réceptionnée.');
        }

        $draft = GoodsReceipt::where('purchase_order_id', $order->id)->where('status', 'draft')->first();
        if ($draft) {
            return $draft;
        }

        $lines = $order->lines()->get()->filter(fn ($l) => $l->received_packs < $l->quantity_packs)->map(fn ($l) => [
            'presentation_id' => $l->presentation_id,
            'purchase_order_line_id' => $l->id,
            'quantity_packs' => $l->quantity_packs - $l->received_packs,
            'unit_price' => (string) $l->unit_price,
        ])->values()->all();

        $location = Location::where('pharmacy_id', $order->pharmacy_id)->where('is_active', true)
            ->orderByRaw("type = 'reserve' desc")->where('is_sellable', true)->first();

        return app(GoodsReceiptService::class)->saveDraft($user, [
            'supplier_id' => $order->supplier_id,
            'location_id' => $location?->id,
            'purchase_order_id' => $order->id,
            'notes' => "Commande {$order->number}",
            'lines' => $lines,
        ]);
    }

    /** Statut recalculé après chaque réception validée. */
    public function refreshStatus(string $orderId): void
    {
        $order = PurchaseOrder::whereKey($orderId)->lockForUpdate()->first();
        if (! $order || $order->status === 'cancelled') {
            return;
        }
        $lines = $order->lines()->get();
        $complete = $lines->every(fn ($l) => $l->received_packs >= $l->quantity_packs);
        $any = $lines->contains(fn ($l) => $l->received_packs > 0);
        $order->update(['status' => $complete ? 'received' : ($any ? 'partially_received' : $order->status)]);
    }

    /** Clôture manuelle d'un reliquat qui ne sera pas livré. */
    public function closeRemainder(User $user, PurchaseOrder $order): PurchaseOrder
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $order) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->pharmacy_id === $user->pharmacy_id, 404);
            if ($order->status !== 'partially_received') {
                throw new PurchaseOrderException('not_partial', "Seule une commande partiellement reçue peut être soldée.");
            }
            GoodsReceipt::where('purchase_order_id', $order->id)->where('status', 'draft')->update(['status' => 'cancelled']);
            $order->update(['status' => 'received']);
            $this->audit($user, $order, 'purchase_order.closed');

            return $order;
        });
    }

    private function lockDraft(PurchaseOrder $order, string $pharmacyId): PurchaseOrder
    {
        $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
        abort_unless($order->pharmacy_id === $pharmacyId, 404);
        if ($order->status !== 'draft') {
            throw new PurchaseOrderException('not_draft', 'Commande déjà envoyée : elle ne peut plus être modifiée.');
        }

        return $order;
    }

    private function authorize(User $user): void
    {
        if (! $user->hasPermission('purchase.manage')) {
            throw new PurchaseOrderException('forbidden', "Vous n'êtes pas autorisé à gérer les commandes.");
        }
    }

    private function audit(User $user, PurchaseOrder $order, string $action): void
    {
        AuditLog::create([
            'pharmacy_id' => $order->pharmacy_id,
            'user_id' => $user->id,
            'action' => $action,
            'auditable_type' => 'purchase_order',
            'auditable_id' => $order->id,
            'new_values' => ['number' => $order->number, 'status' => $order->status, 'total' => (string) $order->total_amount],
        ]);
    }
}
