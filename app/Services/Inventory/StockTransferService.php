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
 * Transfert de stock entre emplacements vendables ou de stockage (Rayon ↔ Réserve…).
 * Une sortie (transfer_out) et une entrée (transfer_in) liées par le même identifiant :
 * le stock total et sa valeur ne changent pas. La quarantaine a ses propres circuits.
 */
class StockTransferService
{
    /** Emplacements entre lesquels on peut transférer. */
    public static function locations(string $pharmacyId): Collection
    {
        return Location::where('pharmacy_id', $pharmacyId)->where('is_active', true)
            ->whereNotIn('type', ['store', 'quarantine'])->orderBy('name')->get();
    }

    /**
     * @return string identifiant du transfert
     */
    public function transfer(User $user, string $batchId, string $fromId, string $toId, int $units, ?string $reason = null): string
    {
        if (! $user->hasPermission('stock.transfer')) {
            throw new InventoryException('forbidden', "Vous n'êtes pas autorisé à transférer du stock.");
        }
        if ($units <= 0) {
            throw new InventoryException('invalid_quantity', 'Indiquez une quantité à transférer.');
        }
        if ($fromId === $toId) {
            throw new InventoryException('same_location', "Choisissez deux emplacements différents.");
        }
        $allowed = self::locations($user->pharmacy_id)->keyBy('id');
        if (! $allowed->has($fromId) || ! $allowed->has($toId)) {
            throw new InventoryException('invalid_location', 'Emplacement non autorisé pour un transfert.');
        }
        $batch = Batch::where('pharmacy_id', $user->pharmacy_id)->findOrFail($batchId);
        if ($batch->status !== 'available') {
            throw new InventoryException('batch_not_available', "Le lot {$batch->lot_number} n'est pas vendable ({$batch->status}) : il relève de la quarantaine.");
        }
        $transferId = (string) Str::ulid();

        DB::transaction(function () use ($user, $batch, $fromId, $toId, $units, $reason, $allowed, $transferId) {
            // Ordre de verrouillage stable : pas d'interblocage entre deux transferts croisés
            $ids = collect([$fromId, $toId])->sort()->values();
            $balances = [];
            foreach ($ids as $id) {
                $balances[$id] = StockBalance::where('batch_id', $batch->id)->where('location_id', $id)->lockForUpdate()->first()
                    ?? StockBalance::create([
                        'pharmacy_id' => $user->pharmacy_id, 'presentation_id' => $batch->presentation_id,
                        'batch_id' => $batch->id, 'location_id' => $id, 'quantity' => 0,
                    ]);
            }
            $from = $balances[$fromId];
            $available = $from->quantity - $from->reserved_quantity;
            if ($units > $available) {
                throw new InventoryException('insufficient_stock',
                    "Stock insuffisant dans {$allowed[$fromId]->name} pour le lot {$batch->lot_number} : $available unités disponibles.");
            }

            $label = "Transfert {$allowed[$fromId]->name} → {$allowed[$toId]->name}".($reason ? " - $reason" : '');
            foreach ([[$from, -$units, 'transfer_out'], [$balances[$toId], $units, 'transfer_in']] as [$balance, $qty, $type]) {
                $balance->quantity += $qty;
                $balance->version++;
                $balance->save();
                StockMovement::create([
                    'pharmacy_id' => $user->pharmacy_id,
                    'presentation_id' => $batch->presentation_id,
                    'batch_id' => $batch->id,
                    'location_id' => $balance->location_id,
                    'type' => $type,
                    'quantity' => $qty,
                    'balance_after' => $balance->quantity,
                    'unit_cost' => $batch->unit_cost,
                    'source_type' => 'stock_transfer',
                    'source_id' => $transferId,
                    'reason' => Str::limit($label, 250),
                    'user_id' => $user->id,
                    'occurred_at' => now(),
                ]);
            }

            AuditLog::create([
                'pharmacy_id' => $user->pharmacy_id,
                'user_id' => $user->id,
                'action' => 'stock.transferred',
                'auditable_type' => 'batch',
                'auditable_id' => $batch->id,
                'new_values' => ['lot' => $batch->lot_number, 'from' => $allowed[$fromId]->code, 'to' => $allowed[$toId]->code, 'units' => $units, 'reason' => $reason],
            ]);
        });

        return $transferId;
    }
}
