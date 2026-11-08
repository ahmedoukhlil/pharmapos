<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Presentation;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ExpiryDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Entrée de stock hors réception fournisseur (stock initial à la mise en service, produits déjà en rayon) :
 * lot existant ou nouveau lot marqué « import initial », mouvement « initial » et trace d'audit.
 */
class StockEntryService
{
    /**
     * @param  int  $units  quantité en unités de base
     * @param  float|null  $packCost  coût d'achat par boîte (défaut : prix d'achat du produit)
     */
    public function add(User $user, string $presentationId, string $lot, string $expiryRaw, int $units, string $locationId, ?float $packCost = null): StockMovement
    {
        if (! $user->hasPermission('stock.adjust')) {
            throw new InventoryException('forbidden', "Vous n'êtes pas autorisé à ajouter du stock.");
        }
        $presentation = Presentation::where('pharmacy_id', $user->pharmacy_id)->findOrFail($presentationId);
        $lot = strtoupper(trim($lot));
        if ($lot === '') {
            throw new InventoryException('lot_required', 'Le numéro de lot est obligatoire.');
        }
        $expiry = ExpiryDate::parse(trim($expiryRaw), $user->pharmacy_id)
            ?? throw new InventoryException('invalid_expiry', 'Péremption illisible : saisissez MM/AAAA.');
        if ($units <= 0) {
            throw new InventoryException('invalid_quantity', 'Indiquez une quantité.');
        }
        $location = StockAdjustmentService::locations($user->pharmacy_id)->firstWhere('id', $locationId)
            ?? throw new InventoryException('invalid_location', 'Emplacement non autorisé.');
        $per = max(1, (int) $presentation->units_per_pack);
        $unitCost = round((float) ($packCost ?? $presentation->purchase_price) / $per, 4);

        return DB::transaction(function () use ($user, $presentation, $lot, $expiryRaw, $expiry, $units, $location, $unitCost) {
            $batch = Batch::where('pharmacy_id', $user->pharmacy_id)->where('presentation_id', $presentation->id)
                ->where('lot_number', $lot)->lockForUpdate()->first();
            if ($batch && ! $batch->expiry_date->isSameMonth($expiry)) {
                throw new InventoryException('lot_expiry_mismatch', "Le lot $lot existe déjà avec la péremption {$batch->expiry_date->format('m/Y')}.");
            }
            if ($batch && $batch->status !== 'available') {
                throw new InventoryException('batch_blocked', "Le lot $lot est bloqué ({$batch->status}).");
            }
            $batch ??= Batch::create([
                'pharmacy_id' => $user->pharmacy_id,
                'presentation_id' => $presentation->id,
                'lot_number' => $lot,
                'expiry_raw' => trim($expiryRaw),
                'expiry_date' => $expiry->toDateString(),
                'unit_cost' => $unitCost,
                'received_on' => now()->toDateString(),
                'status' => 'available',
                'is_initial_import' => true,
            ]);

            $balance = StockBalance::where('batch_id', $batch->id)->where('location_id', $location->id)->lockForUpdate()->first()
                ?? StockBalance::create([
                    'pharmacy_id' => $user->pharmacy_id, 'presentation_id' => $presentation->id,
                    'batch_id' => $batch->id, 'location_id' => $location->id, 'quantity' => 0,
                ]);
            $balance->quantity += $units;
            $balance->version++;
            $balance->save();

            $movement = StockMovement::create([
                'pharmacy_id' => $user->pharmacy_id,
                'presentation_id' => $presentation->id,
                'batch_id' => $batch->id,
                'location_id' => $location->id,
                'type' => 'initial',
                'quantity' => $units,
                'balance_after' => $balance->quantity,
                'unit_cost' => $batch->unit_cost,
                'source_type' => 'stock_entry',
                'source_id' => (string) Str::ulid(),
                'reason' => 'Entrée de stock',
                'user_id' => $user->id,
                'occurred_at' => now(),
            ]);

            AuditLog::create([
                'pharmacy_id' => $user->pharmacy_id,
                'user_id' => $user->id,
                'action' => 'stock.entered',
                'auditable_type' => 'batch',
                'auditable_id' => $batch->id,
                'new_values' => ['lot' => $lot, 'location' => $location->code, 'units' => $units,
                    'value' => round($units * (float) $batch->unit_cost, 2), 'balance_after' => $balance->quantity],
            ]);

            return $movement;
        });
    }
}
