<?php

namespace App\Services\Alerts;

use App\Models\AlertRule;
use App\Models\CashSession;
use App\Models\Presentation;
use App\Models\StockBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alertes calculées à la volée à partir des règles de l'officine (Annexe A) :
 * péremption par paliers, stock sous le seuil de réapprovisionnement, écarts de caisse à valider.
 */
class AlertService
{
    /**
     * Lots en stock arrivant à péremption, regroupés par palier (0 = déjà périmé).
     *
     * @return Collection<int, object> lignes : batch, presentation, quantity, value, days_left, bucket
     */
    public function expiring(string $pharmacyId): Collection
    {
        $rule = $this->rule($pharmacyId, 'expiry');
        if (! $rule) {
            return collect();
        }
        $tiers = collect($rule->parameters['days'] ?? [30, 60, 90, 180])->map(fn ($d) => (int) $d)->sort()->values();
        $today = Carbon::today();

        return StockBalance::query()
            ->join('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->where('stock_balances.pharmacy_id', $pharmacyId)
            ->where('stock_balances.quantity', '>', 0)
            ->whereNotIn('batches.status', ['destroyed', 'returned'])
            ->where('batches.expiry_date', '<=', $today->copy()->addDays($tiers->last()))
            ->groupBy('stock_balances.batch_id')
            ->select('stock_balances.batch_id', DB::raw('SUM(stock_balances.quantity) as quantity'))
            ->get()
            ->load('batch.presentation.medicine')
            ->map(function ($row) use ($today, $tiers) {
                $days = (int) $today->diffInDays($row->batch->expiry_date, false);

                return (object) [
                    'batch' => $row->batch,
                    'presentation' => $row->batch->presentation,
                    'quantity' => (int) $row->quantity,
                    'value' => round($row->quantity * (float) $row->batch->unit_cost, 2),
                    'days_left' => $days,
                    'bucket' => $days < 0 ? 0 : $tiers->first(fn ($t) => $days <= $t),
                ];
            })
            ->sortBy('days_left')->values();
    }

    /** Présentations actives dont le stock vendable (unités de base) est au plus le seuil. */
    public function lowStock(string $pharmacyId): Collection
    {
        if (! $this->rule($pharmacyId, 'low_stock')) {
            return collect();
        }
        $today = Carbon::today()->toDateString();

        // Stock vendable : emplacement vendable, lot disponible et non périmé, moins les réservations
        $sellable = DB::table('stock_balances')
            ->join('locations', 'locations.id', '=', 'stock_balances.location_id')
            ->join('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->where('locations.is_sellable', true)
            ->where('batches.status', 'available')
            ->where('batches.expiry_date', '>=', $today)
            ->groupBy('stock_balances.presentation_id')
            ->select('stock_balances.presentation_id', DB::raw('SUM(stock_balances.quantity - stock_balances.reserved_quantity) as qty'));

        return Presentation::with('medicine')
            ->leftJoinSub($sellable, 's', 's.presentation_id', '=', 'presentations.id')
            ->where('presentations.pharmacy_id', $pharmacyId)
            ->where('presentations.status', 'active')
            ->where('presentations.reorder_threshold', '>', 0)
            ->whereRaw('COALESCE(s.qty, 0) <= presentations.reorder_threshold')
            ->orderByRaw('COALESCE(s.qty, 0) / presentations.reorder_threshold')
            ->select('presentations.*', DB::raw('COALESCE(s.qty, 0) as sellable_quantity'))
            ->get();
    }

    /** Sessions clôturées avec écart hors tolérance, en attente de validation. */
    public function cashVariances(string $pharmacyId): Collection
    {
        $rule = $this->rule($pharmacyId, 'cash_variance');
        if (! $rule) {
            return collect();
        }
        $tolerance = (float) ($rule->parameters['tolerance'] ?? 0);

        return CashSession::with(['register', 'closer'])
            ->where('pharmacy_id', $pharmacyId)->where('status', 'closed')
            ->whereRaw('ABS(difference) > ?', [$tolerance])
            ->orderBy('closed_at')->get();
    }

    /** Nombre affiché dans le menu : périmés + ≤ premier palier, ruptures/seuils, écarts. */
    public function count(string $pharmacyId): int
    {
        $expiring = $this->expiring($pharmacyId);
        $firstTier = $expiring->pluck('bucket')->filter()->min();

        return $expiring->filter(fn ($e) => $e->bucket === 0 || $e->bucket === $firstTier)->count()
            + $this->lowStock($pharmacyId)->count()
            + $this->cashVariances($pharmacyId)->count();
    }

    private function rule(string $pharmacyId, string $type): ?AlertRule
    {
        return AlertRule::where('pharmacy_id', $pharmacyId)->where('type', $type)->where('is_active', true)->first();
    }
}
