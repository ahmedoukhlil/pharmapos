@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $k = $d['kpis'];
    // [clé, libellé, valeur affichée, unité, sous-titre, une hausse est-elle bonne ?]
    $cards = [
        ['revenue', "Chiffre d'affaires", $money($k['revenue']['value']), 'MRU', 'Net des retours : '.$money($k['net']['value']).' MRU', true],
        ['tickets', 'Tickets', number_format($k['tickets']['value'], 0, ',', ' '), '', 'Ventes finalisées', true],
        ['average', 'Panier moyen', $money($k['average']['value']), 'MRU', 'Par ticket', true],
        ['margin', 'Marge brute', $money($k['margin']['value']), 'MRU', 'Taux de marge : '.number_format($k['marginRate']['value'], 1, ',', ' ').' %', true],
        ['returns', 'Retours', $money($k['returns']['value']), 'MRU', 'Remboursés aux clients', false],
    ];
    $s = $d['stock'];
    $chart = fn (string $kind, array $data) => 'x-data="chart(\''.$kind.'\', '.e(json_encode($data)).')" wire:ignore wire:key="'.$kind.'-'.md5(json_encode($data)).'"';
    $panel = 'rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-900/5';
@endphp

<div class="space-y-4">
    {{-- Indicateurs clés --}}
    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($cards as [$key, $label, $value, $unit, $hint, $upIsGood])
            @php
                $change = $k[$key]['change'];
                $good = $change === null || $change == 0 ? null : ($change > 0) === $upIsGood;
            @endphp
            <div @class([$panel, 'relative overflow-hidden', 'lg:col-span-1'])>
                @if ($loop->first) <div class="absolute inset-x-0 top-0 h-1 bg-emerald-600"></div> @endif
                <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-bold tracking-tight text-slate-900 tabular-nums">{{ $value }} <span class="text-sm font-medium text-slate-400">{{ $unit }}</span></p>
                <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                    @if ($change !== null)
                        <span @class(['inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 font-semibold',
                            'bg-emerald-50 text-emerald-700' => $good === true, 'bg-red-50 text-red-700' => $good === false, 'bg-slate-100 text-slate-600' => $good === null])
                              title="Période précédente : {{ $d['previousLabel'] }}">
                            {{ $change > 0 ? '▲' : ($change < 0 ? '▼' : '■') }} {{ number_format(abs($change), 1, ',', ' ') }} %
                        </span>
                    @else
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-500" title="Période précédente : {{ $d['previousLabel'] }}">nouveau</span>
                    @endif
                    <span class="text-slate-500">{{ $hint }}</span>
                </div>
            </div>
        @endforeach
    </section>

    {{-- Évolution --}}
    <section class="{{ $panel }}">
        <div class="mb-4 flex flex-wrap items-baseline gap-x-3">
            <h2 class="font-semibold text-slate-900">Évolution des ventes</h2>
            <p class="text-sm text-slate-500">Chiffre d'affaires et nombre de tickets — comparaison avec le {{ $d['previousLabel'] }}</p>
        </div>
        <div class="h-72" {!! $chart('trend', $d['trend']) !!}><canvas x-ref="canvas"></canvas></div>
    </section>

    <section class="grid gap-4 lg:grid-cols-3">
        <div class="{{ $panel }} lg:col-span-2">
            <h2 class="mb-4 font-semibold text-slate-900">Meilleures ventes</h2>
            @if ($d['topProducts'])
                <div class="h-64" {!! $chart('ranking', $d['topProducts']) !!}><canvas x-ref="canvas"></canvas></div>
            @else
                <p class="flex h-64 items-center justify-center text-sm text-slate-400">Aucune vente sur la période.</p>
            @endif
        </div>
        <div class="{{ $panel }}">
            <h2 class="mb-4 font-semibold text-slate-900">Modes de paiement</h2>
            @if ($d['methods'])
                <div class="h-64" {!! $chart('share', $d['methods']) !!}><canvas x-ref="canvas"></canvas></div>
            @else
                <p class="flex h-64 items-center justify-center text-sm text-slate-400">Aucun encaissement.</p>
            @endif
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-3">
        <div class="{{ $panel }} lg:col-span-2">
            <div class="mb-4 flex items-baseline gap-3">
                <h2 class="font-semibold text-slate-900">Affluence par heure</h2>
                <p class="text-sm text-slate-500">Plus la barre est foncée, plus l'heure est chargée</p>
            </div>
            <div class="h-56" {!! $chart('hours', $d['hours']) !!}><canvas x-ref="canvas"></canvas></div>
        </div>
        <div class="{{ $panel }}">
            <h2 class="mb-4 font-semibold text-slate-900">Ventes par catégorie</h2>
            @if ($d['categories'])
                <div class="h-56" {!! $chart('share', $d['categories']) !!}><canvas x-ref="canvas"></canvas></div>
            @else
                <p class="flex h-56 items-center justify-center text-sm text-slate-400">Aucune vente sur la période.</p>
            @endif
        </div>
    </section>

    {{-- État du stock (instantané, indépendant de la période) --}}
    <section>
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Stock aujourd'hui</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                ['Valeur du stock', $money($s['value']).' MRU', $s['products'].' présentation(s) au CMUP', 'slate', route('reports.index', ['report' => 'stock'])],
                ['Ruptures', $s['outOfStock'], 'Produits sans stock vendable', $s['outOfStock'] ? 'red' : 'slate', route('alerts.index', ['tab' => 'low_stock'])],
                ['Sous le seuil', $s['belowThreshold'], 'À recommander', $s['belowThreshold'] ? 'amber' : 'slate', route('alerts.index', ['tab' => 'low_stock'])],
                ['Périmés en stock', $money($s['expiredValue']).' MRU', 'À retirer de la vente', $s['expiredValue'] ? 'red' : 'slate', route('alerts.index', ['tab' => 'expiry'])],
                ['Péremption ≤ 90 j', $money($s['expiringValue']).' MRU', 'À écouler en priorité', $s['expiringValue'] ? 'amber' : 'slate', route('alerts.index', ['tab' => 'expiry'])],
            ] as [$label, $value, $hint, $tone, $href])
                <a href="{{ $href }}" @class([$panel, 'group block transition hover:-translate-y-0.5 hover:shadow-md',
                    'ring-red-200 bg-red-50/40' => $tone === 'red', 'ring-amber-200 bg-amber-50/40' => $tone === 'amber'])>
                    <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
                    <p @class(['mt-1 text-xl font-bold tabular-nums', 'text-red-700' => $tone === 'red', 'text-amber-700' => $tone === 'amber', 'text-slate-900' => $tone === 'slate'])>{{ $value }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $hint }} <span class="opacity-0 transition group-hover:opacity-100">→</span></p>
                </a>
            @endforeach
        </div>
    </section>
</div>
