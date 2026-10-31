<?php

namespace App\Services\Reports;

use App\Services\Alerts\AlertService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord des rapports : indicateurs comparés à la période précédente de même durée,
 * séries pour les graphiques et état du stock. S'appuie sur les mêmes calculs que les rapports
 * détaillés (ReportService) pour que les chiffres concordent.
 */
class DashboardService
{
    public function __construct(private ReportService $reports, private AlertService $alerts) {}

    public function build(string $pharmacyId, array $f): array
    {
        $days = (int) $f['from']->copy()->startOfDay()->diffInDays($f['to']->copy()->startOfDay()) + 1;
        $previous = array_merge($f, [
            'from' => $f['from']->copy()->subDays($days)->startOfDay(),
            'to' => $f['from']->copy()->subDay()->endOfDay(),
        ]);
        $monthly = $days > 92;
        $f['group'] = $monthly ? 'month' : 'day';

        [$byPeriod, $byProduct, $byCategory] = $this->reports->build('sales', $pharmacyId, $f);
        $now = $this->kpis($byPeriod, $byProduct);
        $before = $this->kpis(...array_slice($this->reports->build('sales', $pharmacyId, $previous), 0, 2));

        [$stock] = $this->reports->build('stock', $pharmacyId, $f);
        [, $byMethod] = $this->reports->build('cash', $pharmacyId, $f);
        $expiring = $this->alerts->expiring($pharmacyId);
        $stockRows = collect($stock['rows']);

        return [
            'kpis' => collect($now)->map(fn ($value, $key) => [
                'value' => $value,
                'previous' => $before[$key],
                'change' => $before[$key] ? round(($value - $before[$key]) / abs($before[$key]) * 100, 1) : null,
            ])->all(),
            'previousLabel' => $previous['from']->format('d/m').' – '.$previous['to']->format('d/m/Y'),
            'trend' => $this->trend($byPeriod['rows'], $f, $monthly),
            'hours' => $this->hours($pharmacyId, $f),
            'topProducts' => collect($byProduct['rows'])->take(6)->map(fn ($r) => ['label' => $r['product'], 'value' => $r['revenue'], 'margin' => $r['margin']])->values()->all(),
            'categories' => $this->top(collect($byCategory['rows'])->map(fn ($r) => ['label' => $r['category'] ?? 'Sans catégorie', 'value' => $r['revenue']]), 5),
            'methods' => collect($byMethod['rows'])->map(fn ($r) => ['label' => $r['method'], 'value' => $r['net']])->filter(fn ($r) => $r['value'] > 0)->values()->all(),
            'stock' => [
                'value' => round($stockRows->sum('value'), 2),
                'products' => $stockRows->count(),
                'outOfStock' => $stockRows->where('state', 'Rupture')->count(),
                'belowThreshold' => $stockRows->where('state', 'Sous le seuil')->count(),
                'expiredValue' => round($expiring->where('bucket', 0)->sum('value'), 2),
                'expiringValue' => round($expiring->where('bucket', '>', 0)->where('bucket', '<=', 90)->sum('value'), 2),
            ],
        ];
    }

    private function kpis(array $byPeriod, array $byProduct): array
    {
        $rows = collect($byPeriod['rows']);
        $revenue = round($rows->sum('revenue'), 2);
        $tickets = (int) $rows->sum('tickets');
        $margin = round(collect($byProduct['rows'])->sum('margin'), 2);

        return [
            'revenue' => $revenue,
            'net' => round($rows->sum('net'), 2),
            'tickets' => $tickets,
            'average' => $tickets ? round($revenue / $tickets, 2) : 0,
            'margin' => $margin,
            'marginRate' => $revenue > 0 ? round($margin / $revenue * 100, 1) : 0,
            'returns' => round($rows->sum('returns'), 2),
        ];
    }

    /** Série continue (jours ou mois sans vente à zéro). */
    private function trend(array $rows, array $f, bool $monthly): array
    {
        $byKey = collect($rows)->keyBy('period');
        $series = [];
        $cursor = $f['from']->copy()->startOfDay();
        while ($cursor->lte($f['to'])) {
            $key = $monthly ? $cursor->format('Y-m') : $cursor->toDateString();
            $series[$key] ??= [
                'label' => $monthly ? ucfirst($cursor->translatedFormat('M Y')) : $cursor->format('d/m'),
                'revenue' => (float) ($byKey[$key]['revenue'] ?? 0),
                'tickets' => (int) ($byKey[$key]['tickets'] ?? 0),
            ];
            $monthly ? $cursor->addMonthNoOverflow()->startOfMonth() : $cursor->addDay();
        }

        return array_values($series);
    }

    /** Tickets et chiffre d'affaires par heure de la journée (affluence). */
    private function hours(string $pharmacyId, array $f): array
    {
        $rows = DB::table('sales')->where('pharmacy_id', $pharmacyId)->where('status', 'finalized')
            ->whereBetween('finalized_at', [$f['from'], $f['to']])
            ->when($f['register_id'] ?? null, fn ($q, $v) => $q->where('register_id', $v))
            ->groupByRaw('HOUR(finalized_at)')
            ->selectRaw('HOUR(finalized_at) as h, COUNT(*) as tickets, SUM(total) as revenue')
            ->get()->keyBy('h');

        $first = min(8, (int) ($rows->keys()->min() ?? 8));
        $last = max(22, (int) ($rows->keys()->max() ?? 22));

        return collect(range($first, $last))->map(fn ($h) => [
            'label' => sprintf('%02dh', $h),
            'tickets' => (int) ($rows[$h]->tickets ?? 0),
            'revenue' => round((float) ($rows[$h]->revenue ?? 0), 2),
        ])->all();
    }

    /** Garde les $n plus grandes parts et regroupe le reste dans « Autres ». */
    private function top($rows, int $n): array
    {
        $rows = $rows->sortByDesc('value')->values();
        if ($rows->count() <= $n) {
            return $rows->all();
        }

        return $rows->take($n - 1)->push(['label' => 'Autres', 'value' => round($rows->slice($n - 1)->sum('value'), 2)])->all();
    }
}
