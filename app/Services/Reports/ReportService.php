<?php

namespace App\Services\Reports;

use App\Services\Alerts\AlertService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rapports essentiels du pilote (PRD §12) : ventes, stock, péremptions et pertes, caisse.
 * Chaque rapport renvoie une liste de tableaux { title, columns, rows, totals } : l'écran et
 * l'export CSV partagent ainsi exactement les mêmes chiffres. Montants en MRU (float arrondi à 2).
 */
class ReportService
{
    public const REPORTS = ['sales' => 'Ventes', 'stock' => 'Stock', 'expiry' => 'Péremptions et pertes', 'cash' => 'Caisse'];

    /** @param array{from: Carbon, to: Carbon, register_id?: ?string, user_id?: ?string, location_id?: ?string, type?: ?string} $f */
    public function build(string $report, string $pharmacyId, array $f): array
    {
        return match ($report) {
            'sales' => $this->sales($pharmacyId, $f),
            'stock' => $this->stock($pharmacyId, $f),
            'expiry' => $this->expiry($pharmacyId, $f),
            'cash' => $this->cash($pharmacyId, $f),
        };
    }

    private function sales(string $pharmacyId, array $f): array
    {
        $sales = fn () => DB::table('sales')
            ->where('sales.pharmacy_id', $pharmacyId)->where('sales.status', 'finalized')
            ->whereBetween('sales.finalized_at', [$f['from'], $f['to']])
            ->when($f['register_id'] ?? null, fn ($q, $v) => $q->where('sales.register_id', $v))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('sales.user_id', $v));

        $grouping = $f['group'] ?? 'day';
        $period = match ($grouping) {
            'month' => "DATE_FORMAT(sales.finalized_at, '%Y-%m')",
            'week' => "DATE_FORMAT(sales.finalized_at, '%x-S%v')",
            default => 'DATE(sales.finalized_at)',
        };

        $returns = DB::table('sale_returns')->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sale_returns.pharmacy_id', $pharmacyId)->whereBetween('sale_returns.created_at', [$f['from'], $f['to']])
            ->when($f['register_id'] ?? null, fn ($q, $v) => $q->where('sales.register_id', $v))
            ->groupByRaw(str_replace('sales.finalized_at', 'sale_returns.created_at', $period))
            ->selectRaw(str_replace('sales.finalized_at', 'sale_returns.created_at', $period).' as period, SUM(sale_returns.total) as returns')
            ->pluck('returns', 'period');

        $byPeriod = $sales()->groupByRaw($period)->orderByRaw($period)
            ->selectRaw("$period as period, COUNT(*) as tickets, SUM(total) as revenue, SUM(discount_total) as discounts")
            ->get()->map(fn ($r) => [
                'period' => $r->period,
                'tickets' => (int) $r->tickets,
                'revenue' => round((float) $r->revenue, 2),
                'average' => round($r->revenue / max(1, $r->tickets), 2),
                'discounts' => round((float) $r->discounts, 2),
                'returns' => round((float) ($returns[$r->period] ?? 0), 2),
                'net' => round($r->revenue - ($returns[$r->period] ?? 0), 2),
            ]);

        // Coût des lots vendus (FEFO) pour la marge
        $cost = DB::table('sale_item_batches')->groupBy('sale_item_id')
            ->selectRaw('sale_item_id, SUM(quantity_base_units * unit_cost) as cost');

        $items = fn () => $sales()->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoinSub($cost, 'c', 'c.sale_item_id', '=', 'sale_items.id');

        $byProduct = $items()->join('presentations', 'presentations.id', '=', 'sale_items.presentation_id')
            ->join('medicines', 'medicines.id', '=', 'presentations.medicine_id')
            ->groupBy('presentations.id', 'presentations.sku', 'medicines.brand_name', 'medicines.strength', 'presentations.base_unit')
            ->selectRaw('presentations.sku, medicines.brand_name, medicines.strength, presentations.base_unit,
                SUM(sale_items.quantity_base_units) as qty, SUM(sale_items.line_total) as revenue, SUM(COALESCE(c.cost, 0)) as cost')
            ->orderByDesc('revenue')->get()->map(fn ($r) => [
                'product' => trim("$r->brand_name $r->strength"),
                'sku' => $r->sku,
                'qty' => (int) $r->qty.' '.$r->base_unit.'(s)',
                'revenue' => round((float) $r->revenue, 2),
                'margin' => round($r->revenue - $r->cost, 2),
                'margin_rate' => $r->revenue > 0 ? round(($r->revenue - $r->cost) / $r->revenue * 100, 1).' %' : '—',
            ]);

        $byCategory = $items()->join('presentations', 'presentations.id', '=', 'sale_items.presentation_id')
            ->join('medicines', 'medicines.id', '=', 'presentations.medicine_id')
            ->leftJoin('categories', 'categories.id', '=', 'medicines.category_id')
            ->groupBy('categories.name', 'medicines.type')
            ->selectRaw("COALESCE(categories.name, 'Sans catégorie') as category, medicines.type, SUM(sale_items.line_total) as revenue, SUM(COALESCE(c.cost, 0)) as cost")
            ->orderByDesc('revenue')->get()->map(fn ($r) => [
                'category' => $r->category,
                'type' => $r->type === 'medicine' ? 'Médicament' : 'Parapharmacie',
                'revenue' => round((float) $r->revenue, 2),
                'margin' => round($r->revenue - $r->cost, 2),
            ]);

        $byCashier = $sales()->join('users', 'users.id', '=', 'sales.user_id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.name, COUNT(*) as tickets, SUM(sales.total) as revenue, SUM(sales.discount_total) as discounts')
            ->orderByDesc('revenue')->get()->map(fn ($r) => [
                'cashier' => $r->name,
                'tickets' => (int) $r->tickets,
                'revenue' => round((float) $r->revenue, 2),
                'average' => round($r->revenue / max(1, $r->tickets), 2),
                'discounts' => round((float) $r->discounts, 2),
            ]);

        return [
            $this->table('Ventes par période', [
                'period' => 'Période', 'tickets' => 'Tickets', 'revenue' => "Chiffre d'affaires", 'average' => 'Panier moyen',
                'discounts' => 'Remises', 'returns' => 'Retours', 'net' => 'Net',
            ], $byPeriod, ['tickets', 'revenue', 'discounts', 'returns', 'net'], ['average' => fn ($rows) => round($rows->sum('revenue') / max(1, $rows->sum('tickets')), 2)]),
            $this->table('Ventes par produit', [
                'product' => 'Produit', 'sku' => 'SKU', 'qty' => 'Quantité', 'revenue' => "Chiffre d'affaires", 'margin' => 'Marge', 'margin_rate' => 'Taux',
            ], $byProduct, ['revenue', 'margin']),
            $this->table('Ventes par catégorie', [
                'category' => 'Catégorie', 'type' => 'Type', 'revenue' => "Chiffre d'affaires", 'margin' => 'Marge',
            ], $byCategory, ['revenue', 'margin']),
            $this->table('Ventes par caissier', [
                'cashier' => 'Caissier', 'tickets' => 'Tickets', 'revenue' => "Chiffre d'affaires", 'average' => 'Panier moyen', 'discounts' => 'Remises',
            ], $byCashier, ['tickets', 'revenue', 'discounts']),
        ];
    }

    /** Stock à date : vendable, quarantaine / non vendable, valorisation au CMUP, ruptures. */
    private function stock(string $pharmacyId, array $f): array
    {
        $today = Carbon::today()->toDateString();

        $rows = DB::table('presentations')
            ->join('medicines', 'medicines.id', '=', 'presentations.medicine_id')
            ->leftJoin('stock_balances', 'stock_balances.presentation_id', '=', 'presentations.id')
            ->leftJoin('locations', 'locations.id', '=', 'stock_balances.location_id')
            ->leftJoin('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->where('presentations.pharmacy_id', $pharmacyId)->where('presentations.status', 'active')
            ->when($f['location_id'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('stock_balances.location_id', $v)->orWhereNull('stock_balances.id')))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('medicines.type', $v))
            ->groupBy('presentations.id', 'presentations.sku', 'medicines.brand_name', 'medicines.strength', 'presentations.base_unit',
                'presentations.weighted_avg_cost', 'presentations.reorder_threshold')
            ->selectRaw("presentations.sku, medicines.brand_name, medicines.strength, presentations.base_unit,
                presentations.weighted_avg_cost as cmup, presentations.reorder_threshold,
                SUM(CASE WHEN locations.is_sellable = 1 AND batches.status = 'available' AND batches.expiry_date >= ? THEN stock_balances.quantity ELSE 0 END) as sellable,
                SUM(CASE WHEN locations.is_sellable = 1 AND batches.status = 'available' AND batches.expiry_date >= ? THEN 0 ELSE COALESCE(stock_balances.quantity, 0) END) as blocked", [$today, $today])
            ->orderBy('medicines.brand_name')->get()->map(function ($r) {
                $total = (int) $r->sellable + (int) $r->blocked;

                return [
                    'product' => trim("$r->brand_name $r->strength"),
                    'sku' => $r->sku,
                    'sellable' => (int) $r->sellable,
                    'blocked' => (int) $r->blocked,
                    'unit' => $r->base_unit,
                    'cmup' => round((float) $r->cmup, 4),
                    'value' => round($total * (float) $r->cmup, 2),
                    'state' => match (true) {
                        (int) $r->sellable <= 0 => 'Rupture',
                        $r->reorder_threshold > 0 && $r->sellable <= $r->reorder_threshold => 'Sous le seuil',
                        default => '',
                    },
                ];
            });

        return [$this->table('Stock et valorisation (CMUP)', [
            'product' => 'Produit', 'sku' => 'SKU', 'sellable' => 'Vendable', 'blocked' => 'Quarantaine / périmé / bloqué',
            'unit' => 'Unité', 'cmup' => 'CMUP', 'value' => 'Valeur', 'state' => 'État',
        ], $rows, ['value'])];
    }

    /** Lots à péremption (paliers de la règle d'alerte) et pertes de la période (destructions, écarts d'inventaire négatifs). */
    private function expiry(string $pharmacyId, array $f): array
    {
        $expiring = app(AlertService::class)->expiring($pharmacyId)->map(fn ($e) => [
            'bucket' => $e->bucket === 0 ? 'Périmé' : "≤ {$e->bucket} j",
            'product' => trim($e->presentation->medicine->brand_name.' '.$e->presentation->medicine->strength),
            'lot' => $e->batch->lot_number,
            'expiry' => $e->batch->expiry_date->format('d/m/Y'),
            'days' => $e->days_left,
            'qty' => $e->quantity,
            'value' => $e->value,
        ]);

        $losses = DB::table('stock_movements')
            ->join('presentations', 'presentations.id', '=', 'stock_movements.presentation_id')
            ->join('medicines', 'medicines.id', '=', 'presentations.medicine_id')
            ->join('batches', 'batches.id', '=', 'stock_movements.batch_id')
            ->where('stock_movements.pharmacy_id', $pharmacyId)
            ->whereBetween('stock_movements.created_at', [$f['from'], $f['to']])
            ->where(fn ($q) => $q->where('stock_movements.type', 'destruction')
                ->orWhere(fn ($q) => $q->whereIn('stock_movements.type', ['inventory', 'adjustment'])->where('stock_movements.quantity', '<', 0)))
            ->orderBy('stock_movements.created_at')
            ->selectRaw('stock_movements.created_at, stock_movements.type, stock_movements.quantity, stock_movements.unit_cost,
                medicines.brand_name, medicines.strength, batches.lot_number')
            ->get()->map(fn ($r) => [
                'date' => Carbon::parse($r->created_at)->format('d/m/Y'),
                'type' => ['destruction' => 'Destruction', 'inventory' => 'Écart inventaire', 'adjustment' => 'Ajustement'][$r->type],
                'product' => trim("$r->brand_name $r->strength"),
                'lot' => $r->lot_number,
                'qty' => abs((int) $r->quantity),
                'value' => round(abs($r->quantity) * (float) $r->unit_cost, 2),
            ]);

        return [
            $this->table('Lots proches de la péremption (stock actuel)', [
                'bucket' => 'Palier', 'product' => 'Produit', 'lot' => 'Lot', 'expiry' => 'Péremption', 'days' => 'Jours', 'qty' => 'Quantité', 'value' => 'Valeur',
            ], $expiring, ['value']),
            $this->table('Pertes de la période', [
                'date' => 'Date', 'type' => 'Nature', 'product' => 'Produit', 'lot' => 'Lot', 'qty' => 'Quantité', 'value' => 'Valeur',
            ], $losses, ['value']),
        ];
    }

    private function cash(string $pharmacyId, array $f): array
    {
        $sessions = DB::table('cash_sessions')
            ->join('registers', 'registers.id', '=', 'cash_sessions.register_id')
            ->leftJoin('users as closer', 'closer.id', '=', 'cash_sessions.closed_by')
            ->where('cash_sessions.pharmacy_id', $pharmacyId)
            ->whereBetween('cash_sessions.opened_at', [$f['from'], $f['to']])
            ->when($f['register_id'] ?? null, fn ($q, $v) => $q->where('cash_sessions.register_id', $v))
            ->orderBy('cash_sessions.opened_at')
            ->select('cash_sessions.*', 'registers.code', 'closer.name as closer_name')->get();

        $statuses = ['open' => 'Ouverte', 'closed' => 'Clôturée', 'validated' => 'Validée'];
        $sessionRows = $sessions->map(fn ($s) => [
            'register' => $s->code,
            'opened' => Carbon::parse($s->opened_at)->format('d/m/Y H:i'),
            'closed' => $s->closed_at ? Carbon::parse($s->closed_at)->format('d/m/Y H:i') : '—',
            'closer' => $s->closer_name ?? '—',
            'expected' => $s->expected_cash !== null ? round((float) $s->expected_cash, 2) : null,
            'counted' => $s->counted_cash !== null ? round((float) $s->counted_cash, 2) : null,
            'difference' => $s->difference !== null ? round((float) $s->difference, 2) : null,
            'status' => $statuses[$s->status],
        ]);

        $byMethod = DB::table('payments')->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->whereIn('payments.cash_session_id', $sessions->pluck('id'))
            ->groupBy('payment_methods.name')
            ->selectRaw("payment_methods.name, SUM(CASE WHEN payments.kind = 'payment' THEN payments.amount ELSE 0 END) as received,
                SUM(CASE WHEN payments.kind = 'refund' THEN -payments.amount ELSE 0 END) as refunded, COUNT(*) as operations")
            ->orderByDesc('received')->get()->map(fn ($r) => [
                'method' => $r->name,
                'operations' => (int) $r->operations,
                'received' => round((float) $r->received, 2),
                'refunded' => round((float) $r->refunded, 2),
                'net' => round($r->received - $r->refunded, 2),
            ]);

        return [
            $this->table('Sessions de caisse', [
                'register' => 'Caisse', 'opened' => 'Ouverture', 'closed' => 'Clôture', 'closer' => 'Clôturée par',
                'expected' => 'Espèces attendues', 'counted' => 'Espèces comptées', 'difference' => 'Écart', 'status' => 'Statut',
            ], $sessionRows, ['difference']),
            $this->table('Encaissements par mode de paiement', [
                'method' => 'Mode', 'operations' => 'Opérations', 'received' => 'Encaissé', 'refunded' => 'Remboursé', 'net' => 'Net',
            ], $byMethod, ['operations', 'received', 'refunded', 'net']),
        ];
    }

    /** Colonnes numériques à totaliser ; $computed : totaux non additifs (ex. panier moyen). */
    private function table(string $title, array $columns, $rows, array $sum = [], array $computed = []): array
    {
        $rows = collect($rows)->values();
        $totals = null;
        if ($rows->isNotEmpty() && ($sum || $computed)) {
            $totals = array_fill_keys(array_keys($columns), '');
            $totals[array_key_first($columns)] = 'Total';
            foreach ($sum as $c) {
                $totals[$c] = round($rows->sum($c), 2);
            }
            foreach ($computed as $c => $fn) {
                $totals[$c] = $fn($rows);
            }
        }

        return ['title' => $title, 'columns' => $columns, 'rows' => $rows->all(), 'totals' => $totals];
    }
}
