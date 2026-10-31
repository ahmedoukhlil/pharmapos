<?php

namespace App\Services\Sales;

use App\Models\StockBalance;
use App\Support\PharmacySettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sélection des lots vendables et proposition FEFO (LOT-03, LOT-04).
 * FEFO est une proposition de sortie physique, pas une méthode de valorisation.
 */
class FefoAllocator
{
    /**
     * Soldes vendables d'une présentation, triés FEFO. Avec $lock, verrouille les lignes
     * dans un ordre déterministe (batch_id, location_id) pour éviter les interblocages (POS-13).
     */
    public function sellableBalances(string $pharmacyId, array $presentationIds, bool $lock = false): Collection
    {
        $query = StockBalance::query()
            ->where('pharmacy_id', $pharmacyId)
            ->whereIn('presentation_id', $presentationIds)
            ->orderBy('batch_id')
            ->orderBy('location_id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $balances = $query->get()->load(['batch', 'location']);

        return $balances
            ->filter(fn (StockBalance $b) => $this->isSellable($b))
            ->sortBy(fn (StockBalance $b) => [$b->batch->expiry_date->format('Ymd'), $b->batch_id, $b->location_id])
            ->values();
    }

    public function isSellable(StockBalance $balance): bool
    {
        return $this->refusalReason($balance) === null;
    }

    /**
     * Motif de refus d'un solde, ou null s'il est vendable. Contrôle côté serveur, indépendant de l'interface.
     */
    public function refusalReason(StockBalance $balance): ?string
    {
        $batch = $balance->batch;

        if (! $balance->location->is_sellable) {
            return 'location_not_sellable';
        }
        if (in_array($batch->status, ['quarantine', 'recalled', 'destroyed', 'returned'], true)) {
            return 'batch_'.$batch->status;
        }
        if ($batch->status === 'expired' || $this->isExpired($batch->expiry_date, $batch->pharmacy_id)) {
            return 'batch_expired';
        }
        if ($batch->status !== 'available') {
            return 'batch_unavailable';
        }

        return null;
    }

    public function isExpired(Carbon $expiryDate, string $pharmacyId): bool
    {
        $margin = (int) PharmacySettings::get($pharmacyId, 'expiry.block_margin_days', 0);

        // Le lot reste vendable jusqu'à la fin de son jour de péremption, moins la marge configurée
        return $expiryDate->copy()->subDays($margin)->endOfDay()->isPast();
    }

    /**
     * Répartit $quantity unités de base sur les soldes, FEFO d'abord.
     *
     * @return array<int, array{balance: StockBalance, quantity: int}>
     */
    public function allocate(Collection $balances, int $quantity): array
    {
        $allocations = [];
        $remaining = $quantity;

        foreach ($balances as $balance) {
            if ($remaining <= 0) {
                break;
            }
            $available = $balance->quantity - $balance->reserved_quantity;
            if ($available <= 0) {
                continue;
            }
            $take = min($available, $remaining);
            $allocations[] = ['balance' => $balance, 'quantity' => $take];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new SaleException('insufficient_stock', 'Stock vendable insuffisant.', [
                'requested' => $quantity,
                'missing' => $remaining,
            ]);
        }

        return $allocations;
    }
}
