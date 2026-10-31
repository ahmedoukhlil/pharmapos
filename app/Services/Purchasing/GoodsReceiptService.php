<?php

namespace App\Services\Purchasing;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStatusHistory;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\PriceHistory;
use App\Models\Presentation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DocumentNumberer;
use App\Support\ExpiryDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Réception fournisseur simple (PRD §10, LOT-01, ACH-03, ACH-04, QA-11) : brouillon modifiable,
 * puis validation atomique qui crée les lots, les entrées de stock et met à jour le CMUP.
 *
 * Données d'un brouillon :
 *   supplier_id, location_id, delivery_note_ref?, notes?
 *   lines[]  presentation_id, quantity_packs, unit_price (par boîte), lot_number, expiry (texte saisi),
 *            decision (accepted|quarantine|refused), anomaly?
 */
class GoodsReceiptService
{
    public function __construct(private readonly DocumentNumberer $numberer) {}

    public function saveDraft(User $user, array $data, ?GoodsReceipt $receipt = null): GoodsReceipt
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $data, $receipt) {
            $pharmacyId = $user->pharmacy_id;

            $supplier = Supplier::where('pharmacy_id', $pharmacyId)->where('is_active', true)->find($data['supplier_id'] ?? null)
                ?? throw new ReceiptException('invalid_supplier', 'Fournisseur invalide.');
            $location = Location::where('pharmacy_id', $pharmacyId)->where('is_active', true)->find($data['location_id'] ?? null)
                ?? throw new ReceiptException('invalid_location', 'Emplacement de réception invalide.');

            $attributes = [
                'supplier_id' => $supplier->id,
                'location_id' => $location->id,
                'delivery_note_ref' => $data['delivery_note_ref'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($receipt) {
                $receipt = GoodsReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
                $this->assertDraft($receipt, $pharmacyId);
                $receipt->update($attributes);
                $receipt->lines()->delete();
            } else {
                $order = null;
                if (! empty($data['purchase_order_id'])) {
                    $order = PurchaseOrder::where('pharmacy_id', $pharmacyId)->where('supplier_id', $supplier->id)
                        ->whereIn('status', ['sent', 'partially_received'])->find($data['purchase_order_id'])
                        ?? throw new ReceiptException('invalid_order', 'Commande introuvable ou non réceptionnable pour ce fournisseur.');
                }
                $receipt = GoodsReceipt::create($attributes + [
                    'purchase_order_id' => $order?->id,
                    'pharmacy_id' => $pharmacyId,
                    'number' => $this->numberer->nextForPharmacy($pharmacyId, 'goods_receipt', 'RC'),
                    'status' => 'draft',
                    'received_at' => now(),
                    'received_by' => $user->id,
                ]);
            }

            $presentations = Presentation::where('pharmacy_id', $pharmacyId)
                ->whereIn('id', array_column($data['lines'] ?? [], 'presentation_id'))
                ->get()->keyBy('id');

            foreach (array_values($data['lines'] ?? []) as $i => $line) {
                $presentation = $presentations->get($line['presentation_id'] ?? null)
                    ?? throw new ReceiptException('unknown_product', 'Produit inconnu.', $i + 1);
                $packs = (int) ($line['quantity_packs'] ?? 0);
                if ($packs <= 0) {
                    throw new ReceiptException('invalid_quantity', 'Quantité invalide.', $i + 1);
                }
                if (! is_numeric($line['unit_price'] ?? null) || (float) $line['unit_price'] < 0) {
                    throw new ReceiptException('invalid_price', "Prix d'achat invalide.", $i + 1);
                }
                $decision = $line['decision'] ?? 'accepted';
                if (! in_array($decision, ['accepted', 'quarantine', 'refused'], true)) {
                    throw new ReceiptException('invalid_decision', 'Décision invalide.', $i + 1);
                }

                $expiryRaw = trim($line['expiry'] ?? '');
                $expiry = $expiryRaw !== '' ? ExpiryDate::parse($expiryRaw, $pharmacyId) : null;
                if ($expiryRaw !== '' && ! $expiry) {
                    throw new ReceiptException('invalid_expiry', "Date de péremption illisible « $expiryRaw » (format MM/AAAA).", $i + 1);
                }

                $poLineId = $line['purchase_order_line_id'] ?? null;
                if ($poLineId && ! ($receipt->purchase_order_id
                    && PurchaseOrderLine::whereKey($poLineId)->where('purchase_order_id', $receipt->purchase_order_id)->where('presentation_id', $presentation->id)->exists())) {
                    $poLineId = null;   // ligne hors commande : simple ligne libre
                }

                $receipt->lines()->create([
                    'line_no' => $i + 1,
                    'purchase_order_line_id' => $poLineId,
                    'presentation_id' => $presentation->id,
                    'quantity_packs' => $packs,
                    'quantity_units' => $packs * $presentation->units_per_pack,
                    'unit_price' => $line['unit_price'],
                    'lot_number' => Str::upper(trim($line['lot_number'] ?? '')) ?: null,
                    'expiry_raw' => $expiryRaw ?: null,
                    'expiry_date' => $expiry,
                    'decision' => $decision,
                    'anomaly' => $line['anomaly'] ?? null,
                ]);
            }

            return $receipt->load('lines');
        });
    }

    /**
     * Validation : lots créés ou complétés, stock entré (rayon ou quarantaine), CMUP et prix d'achat mis à jour.
     */
    public function validate(User $user, GoodsReceipt $receipt): GoodsReceipt
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $receipt) {
            $receipt = GoodsReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($receipt, $user->pharmacy_id);

            $lines = $receipt->lines()->with('presentation')->orderBy('line_no')->get();
            if ($lines->isEmpty()) {
                throw new ReceiptException('empty_receipt', 'La réception ne contient aucune ligne.');
            }

            $quarantine = null;
            foreach ($lines as $i => $line) {
                if ($line->decision === 'refused') {
                    continue;
                }
                if (! $line->lot_number || ! $line->expiry_date) {
                    throw new ReceiptException('lot_required', 'Numéro de lot et péremption obligatoires.', $i + 1);
                }
                if ($line->expiry_date->endOfDay()->isPast()) {
                    throw new ReceiptException('expired_on_receipt', 'Produit déjà périmé : il doit être refusé.', $i + 1);
                }

                $location = $receipt->location;
                if ($line->decision === 'quarantine') {
                    $quarantine ??= Location::where('pharmacy_id', $receipt->pharmacy_id)->where('type', 'quarantine')->where('is_active', true)->first()
                        ?? throw new ReceiptException('no_quarantine_location', "Aucun emplacement de quarantaine n'est configuré.");
                    $location = $quarantine;
                }

                $batch = $this->resolveBatch($user, $receipt, $line, $i + 1);
                $unitCost = round((float) $line->unit_price / $line->presentation->units_per_pack, 4);

                $this->updateCosts($user, $line->presentation, $line->quantity_units, $unitCost, (string) $line->unit_price);
                $this->addStock($user, $receipt, $batch, $location, $line->quantity_units, $unitCost);

                $line->update(['batch_id' => $batch->id]);
            }

            $receipt->update(['status' => 'validated', 'validated_by' => $user->id, 'validated_at' => now()]);

            // ACH-06 : quantités reçues reportées sur la commande (les refus ne comptent pas)
            if ($receipt->purchase_order_id) {
                foreach ($lines->where('decision', '!=', 'refused')->whereNotNull('purchase_order_line_id')->groupBy('purchase_order_line_id') as $poLineId => $group) {
                    PurchaseOrderLine::whereKey($poLineId)->increment('received_packs', $group->sum('quantity_packs'));
                }
                app(PurchaseOrderService::class)->refreshStatus($receipt->purchase_order_id);
            }

            AuditLog::create([
                'pharmacy_id' => $receipt->pharmacy_id,
                'user_id' => $user->id,
                'action' => 'goods_receipt.validated',
                'auditable_type' => 'goods_receipt',
                'auditable_id' => $receipt->id,
                'new_values' => [
                    'number' => $receipt->number,
                    'lines' => $lines->count(),
                    'decisions' => $lines->countBy('decision')->all(),
                ],
            ]);

            OutboxEvent::create([
                'event_id' => (string) Str::uuid(),
                'pharmacy_id' => $receipt->pharmacy_id,
                'aggregate_type' => 'goods_receipt',
                'aggregate_id' => $receipt->id,
                'event_type' => 'goods_receipt.validated',
                'sequence' => 1,
                'payload' => ['number' => $receipt->number, 'supplier_id' => $receipt->supplier_id],
                'occurred_at' => now(),
            ]);

            return $receipt->load('lines.batch');
        });
    }

    public function cancel(User $user, GoodsReceipt $receipt): GoodsReceipt
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $receipt) {
            $receipt = GoodsReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($receipt, $user->pharmacy_id);
            $receipt->update(['status' => 'cancelled']);

            return $receipt;
        });
    }

    /**
     * Lot existant (même produit, numéro et péremption) ou nouveau lot. Un lot bloqué ne peut pas être réapprovisionné.
     */
    private function resolveBatch(User $user, GoodsReceipt $receipt, $line, int $lineNo): Batch
    {
        $batch = Batch::where('pharmacy_id', $receipt->pharmacy_id)
            ->where('presentation_id', $line->presentation_id)
            ->where('lot_number', $line->lot_number)
            ->lockForUpdate()
            ->first();

        if ($batch && ! $batch->expiry_date->isSameDay($line->expiry_date)) {
            throw new ReceiptException('lot_expiry_mismatch',
                "Le lot {$line->lot_number} existe déjà avec la péremption {$batch->expiry_date->format('m/Y')}.", $lineNo);
        }
        if ($batch && ! in_array($batch->status, ['available', 'quarantine'], true)) {
            throw new ReceiptException('batch_blocked', "Le lot {$line->lot_number} est {$batch->status} : réception impossible.", $lineNo);
        }

        if ($batch) {
            return $batch;
        }

        $batch = Batch::create([
            'pharmacy_id' => $receipt->pharmacy_id,
            'presentation_id' => $line->presentation_id,
            'supplier_id' => $receipt->supplier_id,
            'goods_receipt_id' => $receipt->id,
            'lot_number' => $line->lot_number,
            'expiry_raw' => $line->expiry_raw,
            'expiry_date' => $line->expiry_date,
            'unit_cost' => round((float) $line->unit_price / $line->presentation->units_per_pack, 4),
            'received_on' => now()->toDateString(),
            'status' => $line->decision === 'quarantine' ? 'quarantine' : 'available',
        ]);

        if ($batch->status === 'quarantine') {
            BatchStatusHistory::create([
                'batch_id' => $batch->id,
                'to_status' => 'quarantine',
                'reason' => $line->anomaly ?: 'Mise en quarantaine à la réception',
                'changed_by' => $user->id,
            ]);
        }

        return $batch;
    }

    /** CMUP par unité de base (valorisation par défaut, Annexe A) et dernier prix d'achat. */
    private function updateCosts(User $user, Presentation $presentation, int $units, float $unitCost, string $packPrice): void
    {
        $presentation = Presentation::whereKey($presentation->id)->lockForUpdate()->firstOrFail();
        $onHand = (int) StockBalance::where('presentation_id', $presentation->id)->sum('quantity');
        $previousAvg = (float) $presentation->weighted_avg_cost;

        $newAvg = $onHand + $units > 0
            ? ($onHand * $previousAvg + $units * $unitCost) / ($onHand + $units)
            : $unitCost;

        $previousPrice = (string) $presentation->purchase_price;
        $presentation->update(['weighted_avg_cost' => round($newAvg, 4), 'purchase_price' => $packPrice]);

        if ((int) round((float) $previousPrice * 100) !== (int) round((float) $packPrice * 100)) {
            PriceHistory::create([
                'pharmacy_id' => $presentation->pharmacy_id,
                'presentation_id' => $presentation->id,
                'price_type' => 'purchase',
                'old_price' => $previousPrice,
                'new_price' => $packPrice,
                'changed_by' => $user->id,
                'reason' => 'Réception fournisseur',
                'effective_at' => now(),
            ]);
        }
    }

    private function addStock(User $user, GoodsReceipt $receipt, Batch $batch, Location $location, int $units, float $unitCost): void
    {
        $balance = StockBalance::where('batch_id', $batch->id)->where('location_id', $location->id)->lockForUpdate()->first()
            ?? StockBalance::create([
                'pharmacy_id' => $receipt->pharmacy_id,
                'presentation_id' => $batch->presentation_id,
                'batch_id' => $batch->id,
                'location_id' => $location->id,
                'quantity' => 0,
            ]);

        $balance->quantity += $units;
        $balance->version++;
        $balance->save();

        StockMovement::create([
            'pharmacy_id' => $receipt->pharmacy_id,
            'presentation_id' => $batch->presentation_id,
            'batch_id' => $batch->id,
            'location_id' => $location->id,
            'type' => 'receipt',
            'quantity' => $units,
            'balance_after' => $balance->quantity,
            'unit_cost' => $unitCost,
            'source_type' => 'goods_receipt',
            'source_id' => $receipt->id,
            'user_id' => $user->id,
            'occurred_at' => now(),
        ]);
    }

    private function authorize(User $user): void
    {
        if (! $user->hasPermission('purchase.manage')) {
            throw new ReceiptException('forbidden', "Vous n'êtes pas autorisé à gérer les réceptions.");
        }
    }

    private function assertDraft(GoodsReceipt $receipt, string $pharmacyId): void
    {
        if ($receipt->pharmacy_id !== $pharmacyId) {
            throw new ReceiptException('forbidden', 'Réception introuvable.');
        }
        if ($receipt->status !== 'draft') {
            throw new ReceiptException('not_draft', 'Cette réception est déjà validée ou annulée.');
        }
    }
}
