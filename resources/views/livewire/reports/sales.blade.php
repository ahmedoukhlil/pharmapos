{{--
    Rapport des ventes en cartes : chiffres clés, puis classements (produits, catégories, caissiers) et détail par période.
    Mêmes chiffres que l'export CSV et l'impression (ReportService::sales).
--}}
@php
    [$byPeriod, $byProduct, $byCategory, $byCashier] = $tables;
    $t = $byPeriod['totals'] ?? [];
    $margin = collect($byProduct['rows'])->sum('margin');
    $revenue = (float) ($t['revenue'] ?? 0);
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $day = fn ($p) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $p) ? ['Dim.', 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.'][\Illuminate\Support\Carbon::parse($p)->dayOfWeek].' '.\Illuminate\Support\Carbon::parse($p)->format('d/m') : $p;
    $export = fn ($i) => $canExport ? route('reports.export', $query + ['table' => $i]) : null;
    $kpis = [
        ['Chiffre d\'affaires net', $money($t['net'] ?? 0), 'MRU', 'emerald'],
        ['Reçus', number_format((int) ($t['tickets'] ?? 0), 0, ',', ' '), '', 'slate'],
        ['Panier moyen', $money($t['average'] ?? 0), 'MRU', 'slate'],
        ['Marge', $money($margin), $revenue > 0 ? number_format($margin / $revenue * 100, 1, ',', ' ').' %' : '', 'emerald'],
        ['Remises', $money($t['discounts'] ?? 0), 'MRU', 'amber'],
        ['Retours', $money($t['returns'] ?? 0), 'MRU', 'red'],
    ];
    $card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 print:break-inside-avoid print:shadow-none';
@endphp

@if (! $byPeriod['rows'])
    <div class="{{ $card }} py-10 text-center text-slate-500">Aucune vente pour ces filtres.</div>
@else
    {{-- Chiffres clés --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($kpis as [$label, $value, $suffix, $tone])
            <div @class(['rounded-2xl p-4 shadow-sm ring-1', 'bg-emerald-700 text-white ring-emerald-700' => $loop->first, 'bg-white ring-slate-100' => ! $loop->first])>
                <p @class(['text-xs font-medium uppercase tracking-wide', 'text-emerald-100' => $loop->first, 'text-slate-500' => ! $loop->first])>{{ $label }}</p>
                <p @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-amber-700' => $tone === 'amber' && ($t['discounts'] ?? 0) > 0, 'text-red-700' => $tone === 'red' && ($t['returns'] ?? 0) > 0])>{{ $value }}</p>
                @if ($suffix) <p @class(['text-xs', 'text-emerald-100' => $loop->first, 'text-slate-500' => ! $loop->first])>{{ $suffix }}</p> @endif
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Meilleurs produits --}}
        <section class="{{ $card }} lg:row-span-2">
            <header class="mb-3 flex items-center">
                <h2 class="font-semibold">Produits vendus</h2>
                @if ($export(1)) <a href="{{ $export(1) }}" class="ms-auto text-xs text-emerald-800 hover:underline print:hidden">Exporter CSV</a> @endif
            </header>
            @php $top = max(1, collect($byProduct['rows'])->max('revenue')); @endphp
            <ol class="space-y-3">
                @foreach ($byProduct['rows'] as $p)
                    <li>
                        <div class="flex items-baseline gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-xs font-semibold text-emerald-800">{{ $loop->iteration }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $p['product'] }}</span>
                                <span class="text-xs text-slate-500">{{ $p['qty'] }} · marge {{ $money($p['margin']) }} ({{ $p['margin_rate'] }})</span>
                            </span>
                            <span class="font-semibold tabular-nums">{{ $money($p['revenue']) }}</span>
                        </div>
                        <div class="ms-9 mt-1 h-1.5 rounded-full bg-slate-100">
                            <div class="h-1.5 rounded-full bg-emerald-600" style="width: {{ round($p['revenue'] / $top * 100) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Catégories --}}
        <section class="{{ $card }}">
            <header class="mb-3 flex items-center">
                <h2 class="font-semibold">Par catégorie</h2>
                @if ($export(2)) <a href="{{ $export(2) }}" class="ms-auto text-xs text-emerald-800 hover:underline print:hidden">Exporter CSV</a> @endif
            </header>
            <ul class="space-y-3">
                @foreach ($byCategory['rows'] as $c)
                    @php $share = $revenue > 0 ? $c['revenue'] / $revenue * 100 : 0; @endphp
                    <li>
                        <div class="flex items-baseline gap-2">
                            <span class="font-medium">{{ $c['category'] }}</span>
                            <span class="rounded-full bg-slate-100 px-2 text-xs text-slate-600">{{ $c['type'] }}</span>
                            <span class="ms-auto font-semibold tabular-nums">{{ $money($c['revenue']) }}</span>
                        </div>
                        <div class="mt-1 flex items-center gap-2">
                            <div class="h-1.5 flex-1 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-sky-500" style="width: {{ round($share) }}%"></div></div>
                            <span class="w-24 text-end text-xs text-slate-500">{{ number_format($share, 0) }} % · marge {{ $money($c['margin']) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- Caissiers --}}
        <section class="{{ $card }}">
            <header class="mb-3 flex items-center">
                <h2 class="font-semibold">Par caissier</h2>
                @if ($export(3)) <a href="{{ $export(3) }}" class="ms-auto text-xs text-emerald-800 hover:underline print:hidden">Exporter CSV</a> @endif
            </header>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($byCashier['rows'] as $c)
                    <div class="rounded-xl bg-slate-50 p-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-700 text-sm font-semibold text-white">{{ mb_strtoupper(mb_substr($c['cashier'], 0, 1)) }}</span>
                            <span class="truncate font-medium">{{ $c['cashier'] }}</span>
                        </div>
                        <p class="mt-2 text-lg font-semibold tabular-nums">{{ $money($c['revenue']) }}</p>
                        <p class="text-xs text-slate-500">{{ $c['tickets'] }} reçu(s) · panier {{ $money($c['average']) }}@if ($c['discounts'] > 0) · remises {{ $money($c['discounts']) }}@endif</p>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    {{-- Détail par période --}}
    <section class="{{ $card }}">
        <header class="mb-3 flex items-center">
            <h2 class="font-semibold">Détail par période</h2>
            @if ($export(0)) <a href="{{ $export(0) }}" class="ms-auto text-xs text-emerald-800 hover:underline print:hidden">Exporter CSV</a> @endif
        </header>
        @php $peak = max(1, collect($byPeriod['rows'])->max('net')); @endphp
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
            @foreach ($byPeriod['rows'] as $r)
                <div class="rounded-xl border border-slate-100 p-3">
                    <p class="text-xs font-medium text-slate-500">{{ ucfirst($day($r['period'])) }}</p>
                    <p class="mt-1 font-semibold tabular-nums">{{ $money($r['net']) }}</p>
                    <p class="text-xs text-slate-500">{{ $r['tickets'] }} reçu(s)@if ($r['returns'] > 0) · <span class="text-red-700">retours {{ $money($r['returns']) }}</span>@endif</p>
                    <div class="mt-2 h-1 rounded-full bg-slate-100"><div class="h-1 rounded-full bg-emerald-600" style="width: {{ round(max(0, $r['net']) / $peak * 100) }}%"></div></div>
                </div>
            @endforeach
        </div>
    </section>
@endif
