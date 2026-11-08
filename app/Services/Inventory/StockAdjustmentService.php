<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Correction ponctuelle du stock d'un lot dans un emplacement (casse, péremption, erreur de saisie…),
 * sans ouvrir d'inventaire. Un mouvement « adjustment » ou « destruction », un motif obligatoire,
 * et une trace dans le journal d'audit avec la valeur concernée.
 */
class StockAdjustmentService
{
    /** motif => [libellé, sens autorisés ('out', 'in'), type de mouvement, permission] */
    public const REASONS = [
        'breakage' => ['Casse / produit abîmé', ['out'], 'adjustment', 'stock.adjust'],
        'expired' => ['Périmé : sortie pour destruction', ['out'], 'destruction', 'batch.destroy'],
        'entry_error' => ['Erreur de saisie', ['out', 'in'], 'adjustment', 'stock.adjust'],
        'theft' => ['Vol ou perte', ['out'], 'adjustment', 'stock.adjust'],
        'internal_use' => ['Usage interne / échantillon', ['out'], 'adjustment', 'stock.adjust'],
        'found' => ['Produit retrouvé', ['in'], 'adjustment', 'stock.adjust'],
        'other' => ['Autre (préciser)', ['out', 'in'], 'adjustment', 'stock.adjust'],
    ];

    /** Emplacements où l'on peut corriger (tous, sauf l'officine elle-même). */
    public static function locations(string $pharmacyId): Collection
    {
        return Location::where('pharmacy_id', $pharmacyId)->where('is_active', true)
            ->where('type', '!=', 'store')->orderBy('name')->get();
    }

    /**
     * @param  int  $units  quantité en unités de base, positive
     * @param  string  $direction  'out' (retirer) ou 'in' (ajouter)
     * @return StockMovement le mouvement créé
     */
    public function adjust(User $user, string $batchId, string $locationId, string $direction, int $units, string $reason, ?string $comment = null): StockMovement
    {
        $rule = self::REASONS[$reason] ?? throw new InventoryException('invalid_reason', 'Choisissez un motif.');
        [$label, $directions, $type, $permission] = $rule;
        if (! $user->hasPermission($permission)) {
            throw new InventoryException('forbidden', $permission === 'batch.destroy'
                ? "Vous n'êtes pas autorisé à sortir un lot pour destruction."
                : "Vous n'êtes pas autorisé à corriger le stock.");
        }
        if (! in_array($direction, $directions, true)) {
            throw new InventoryException('invalid_direction', $direction === 'in'
                ? "« {$label} » ne peut que retirer du stock."
                : "« {$label} » ne peut qu'ajouter du stock.");
        }
        if ($units <= 0) {
            throw new InventoryException('invalid_quantity', 'Indiquez une quantité.');
        }
        $comment = trim((string) $comment) ?: null;
        if ($reason === 'other' && ! $comment) {
            throw new InventoryException('comment_required', 'Précisez le motif dans le commentaire.');
        }
        $location = self::locations($user->pharmacy_id)->firstWhere('id', $locationId)
            ?? throw new InventoryException('invalid_location', 'Emplacement non autorisé.');
        $batch = Batch::where('pharmacy_id', $user->pharmacy_id)->findOrFail($batchId);

        return DB::transaction(function () use ($user, $batch, $location, $direction, $units, $reason, $label, $type, $comment) {
            $balance = StockBalance::where('batch_id', $batch->id)->where('location_id', $location->id)->lockForUpdate()->first();
            if ($direction === 'out') {
                $available = $balance ? $balance->quantity - $balance->reserved_quantity : 0;
                if ($units > $available) {
                    throw new InventoryException('insufficient_stock',
                        "Stock insuffisant dans {$location->name} pour le lot {$batch->lot_number} : {$available} unités disponibles.");
                }
            }
            $balance ??= StockBalance::create([
                'pharmacy_id' => $user->pharmacy_id, 'presentation_id' => $batch->presentation_id,
                'batch_id' => $batch->id, 'location_id' => $location->id, 'quantity' => 0,
            ]);
            $qty = $direction === 'out' ? -$units : $units;
            $balance->quantity += $qty;
            $balance->version++;
            $balance->save();

            $movement = StockMovement::create([
                'pharmacy_id' => $user->pharmacy_id,
                'presentation_id' => $batch->presentation_id,
                'batch_id' => $batch->id,
                'location_id' => $location->id,
                'type' => $type,
                'quantity' => $qty,
                'balance_after' => $balance->quantity,
                'unit_cost' => $batch->unit_cost,
                'source_type' => 'stock_adjustment',
                'source_id' => (string) Str::ulid(),
                'reason' => Str::limit($label.($comment ? " - $comment" : ''), 250),
                'user_id' => $user->id,
                'occurred_at' => now(),
            ]);

            AuditLog::create([
                'pharmacy_id' => $user->pharmacy_id,
                'user_id' => $user->id,
                'action' => $type === 'destruction' ? 'stock.destroyed' : 'stock.adjusted',
                'auditable_type' => 'batch',
                'auditable_id' => $batch->id,
                'new_values' => [
                    'lot' => $batch->lot_number, 'location' => $location->code, 'units' => $qty, 'reason' => $reason,
                    'comment' => $comment, 'value' => round($qty * (float) $batch->unit_cost, 2), 'balance_after' => $balance->quantity,
                ],
            ]);

            return $movement;
        });
    }
}
