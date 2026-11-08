@props(['register' => null])

@if (auth()->user()->is_super_admin)
    <x-admin-nav />
@else
@php
    $user = auth()->user();
    $q = $register ? ['caisse' => $register] : [];
    // Menus regroupés : [libellé => [[route, paramètres, libellé, motif actif, visible]]]
    $can = fn (string ...$p) => collect($p)->contains(fn ($x) => $user->hasPermission($x));
    $canPos = $can('sale.create', 'cash.open_close');
    $groups = [
        'Ventes' => [
            ['pos', $q, 'Caisse', 'pos', $canPos],
            ['returns', $q, 'Retours', 'returns', $can('sale.refund')],
            ['pos.close', $q, 'Clôture', 'pos.close', $can('cash.open_close')],
            ['sessions.index', [], 'Sessions', 'sessions.*', $can('cash.open_close', 'cash.validate', 'report.view')],
        ],
        'Achats' => [
            ['orders.index', [], 'Commandes', 'orders.*', $can('purchase.manage')],
            ['receipts.index', [], 'Réceptions', 'receipts.*', $can('purchase.manage')],
            ['invoices.index', [], 'Factures', 'invoices.*', $user->hasPermission('purchase.invoice')],
            ['suppliers.index', [], 'Fournisseurs', 'suppliers.*', $can('purchase.manage')],
        ],
        'Stock' => [
            ['catalogue.index', [], 'Catalogue', 'catalogue.*', $can('catalogue.view')],
            ['inventories.index', [], 'Inventaires', 'inventories.*', $can('inventory.count')],
            ['transfers', [], 'Transferts Rayon ↔ Réserve', 'transfers', $user->hasPermission('stock.transfer')],
            ['stock-adjustments', [], 'Correction de stock', 'stock-adjustments', $can('stock.adjust', 'batch.destroy')],
        ],
        'Gestion' => [
            ['reports.index', [], 'Rapports', 'reports.*', $user->hasPermission('report.view')],
            ['users.index', [], 'Utilisateurs', 'users.*', $user->hasPermission('user.manage')],
            ['store-layout.index', [], 'Caisses et emplacements', 'store-layout.*', $user->hasPermission('settings.manage')],
            ['payment-methods.index', [], 'Modes de paiement', 'payment-methods.*', $user->hasPermission('settings.manage')],
            ['backups.index', [], 'Sauvegardes', 'backups.*', \App\Livewire\System\BackupIndex::allowed()],
        ],
    ];
    $groups = array_filter(array_map(fn ($items) => array_values(array_filter($items, fn ($i) => $i[4])), $groups));

    $alertCount = null;
    if ($user->hasPermission('stock.view')) {
        $pharmacyId = $user->pharmacy_id;
        $alertCount = cache()->remember("alerts.count.$pharmacyId", 60, fn () => app(\App\Services\Alerts\AlertService::class)->count($pharmacyId));
    }
@endphp

{{-- Mobile d'abord : barre compacte et tiroir ; menus déroulants à partir de lg --}}
<header x-data="{ open: null, drawer: false }" @click.outside="open = null; drawer = false" @keydown.escape.window="open = null; drawer = false"
        class="sticky top-0 z-40 bg-emerald-800 text-white print:hidden lg:relative" style="padding-top: env(safe-area-inset-top)">
    <div class="flex items-center gap-2 px-3 py-2 lg:gap-3 lg:px-4">
        <a href="{{ route('pos', $q) }}" class="flex shrink-0 items-center gap-2 font-semibold">
            <img src="/images/icon-64.png" alt="" class="size-8 rounded-md bg-white p-0.5">
            <span class="hidden sm:inline">PharmaPOS</span>
        </a>

        <nav class="hidden gap-1 whitespace-nowrap text-sm lg:flex" data-tour-order="1" data-tour-title="Le menu" data-tour="Toutes les fonctions sont rangées ici : Ventes, Achats, Stock et Gestion. Vous ne voyez que les écrans autorisés pour votre compte.">
            @if ($canPos)
            <a href="{{ route('pos', $q) }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('pos')])>Caisse</a>
            @endif
            @foreach ($groups as $label => $items)
                <div class="relative">
                    <button type="button" @click="open = open === '{{ $label }}' ? null : '{{ $label }}'" :aria-expanded="open === '{{ $label }}'"
                            @class(['flex items-center gap-1 rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => collect($items)->contains(fn ($i) => request()->routeIs($i[3]))])>
                        {{ $label }}
                        <svg class="size-3 opacity-70" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.2 7.2a.75.75 0 0 1 1.06 0L10 10.94l3.74-3.74a.75.75 0 1 1 1.06 1.06l-4.27 4.27a.75.75 0 0 1-1.06 0L5.2 8.26a.75.75 0 0 1 0-1.06Z"/></svg>
                    </button>
                    <div x-show="open === '{{ $label }}'" x-transition.opacity.duration.100ms x-cloak
                         class="absolute start-0 z-50 mt-1 min-w-44 rounded-lg bg-white py-1 text-slate-800 shadow-lg ring-1 ring-black/5">
                        @foreach ($items as [$route, $params, $text, $pattern])
                            <a href="{{ route($route, $params) }}" @class(['block px-3 py-2 hover:bg-emerald-50', 'font-semibold text-emerald-800' => request()->routeIs($pattern)])>{{ $text }}</a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        @if ($alertCount !== null)
            <a href="{{ route('alerts.index') }}" title="Alertes" data-tour-order="2" data-tour-title="Alertes" data-tour="Ruptures, produits bientôt périmés et lots à retirer. Le chiffre rouge indique le nombre d'alertes." @class(['hidden shrink-0 lg:flex items-center rounded px-2 py-1 text-sm hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('alerts.*')])>
                <svg class="size-5 lg:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                <span class="hidden lg:inline">Alertes</span>
                @if ($alertCount) <span class="ms-1 rounded-full bg-red-600 px-2 text-xs font-semibold">{{ $alertCount }}</span> @endif
            </a>
        @endif

        <div class="ms-auto flex min-w-0 items-center gap-2 lg:gap-3">
            {{ $slot }}
            <button type="button" onclick="window.dispatchEvent(new Event('start-tour'))" title="Visite guidée de cet écran" aria-label="Visite guidée"
                    class="flex size-8 shrink-0 items-center justify-center rounded-full border border-emerald-300/60 text-sm font-bold hover:bg-emerald-700" data-tour-order="95" data-tour-title="Visite guidée" data-tour="Chaque écran a sa visite. Touchez ce ? pour la revoir quand vous voulez.">?</button>
            <a href="{{ route('password.edit') }}" class="hidden whitespace-nowrap rounded px-2 py-1 text-sm hover:bg-emerald-700 lg:block" title="Mon mot de passe" data-tour-order="90" data-tour-title="Votre compte" data-tour="Touchez votre nom pour changer votre mot de passe.">{{ $user->name }}</a>
            <form method="POST" action="{{ route('logout') }}" class="hidden lg:block">
                @csrf
                <button class="rounded px-2 py-1 text-sm hover:bg-emerald-700">Déconnexion</button>
            </form>
            <button type="button" @click="drawer = ! drawer" :aria-expanded="drawer" aria-label="Menu"
                    class="shrink-0 rounded p-2 hover:bg-emerald-700 lg:hidden">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path x-show="! drawer" d="M4 6h16M4 12h16M4 18h16"/><path x-show="drawer" x-cloak d="M6 6l12 12M18 6 6 18"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- Menu du téléphone : tuiles avec icône, 3 par ligne --}}
    @php
        $icons = [
            'pos' => 'M3 7h18M5 7l1 13h12l1-13M9 7V5a3 3 0 0 1 6 0v2',
            'returns' => 'M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
            'pos.close' => 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4',
            'sessions.index' => 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
            'orders.index' => 'M9 5h6M9 3h6v4H9zM5 5h2v16h10V5h2M9 12h6M9 16h4',
            'receipts.index' => 'M3 8l9-5 9 5v8l-9 5-9-5zM3 8l9 5 9-5M12 13v8',
            'invoices.index' => 'M6 3h9l3 3v15H6zM9 9h6M9 13h6M9 17h4',
            'suppliers.index' => 'M3 7h11v9H3zM14 10h4l3 3v3h-7M7 19a2 2 0 1 0 0-.01M17 19a2 2 0 1 0 0-.01',
            'catalogue.index' => 'M4 6h16M4 12h16M4 18h10',
            'inventories.index' => 'M9 11l2 2 4-4M5 4h14v16H5z',
            'transfers' => 'M7 4 3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7',
            'stock-adjustments' => 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z',
            'reports.index' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
            'users.index' => 'M16 19v-1a4 4 0 0 0-8 0v1M12 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6M20 19v-1a3 3 0 0 0-2-2.8M4 19v-1a3 3 0 0 1 2-2.8',
            'store-layout.index' => 'M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6',
            'payment-methods.index' => 'M2 6h20v12H2zM2 10h20M6 15h4',
            'backups.index' => 'M12 3v12M7 10l5 5 5-5M4 19h16',
        ];
        $short = ['Transferts Rayon ↔ Réserve' => 'Transferts', 'Correction de stock' => 'Corrections', 'Caisses et emplacements' => 'Emplacements', 'Modes de paiement' => 'Paiements'];
    @endphp
    <nav x-show="drawer" x-cloak x-transition.opacity.duration.100ms
         class="absolute inset-x-0 top-full z-50 h-[calc(100dvh-7.5rem-env(safe-area-inset-top)-env(safe-area-inset-bottom))] overflow-y-auto bg-slate-50 px-3 pb-4 text-slate-800 shadow-lg lg:hidden">
        <div class="mt-3 flex items-center gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-900/5">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 font-semibold text-emerald-800">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
            <a href="{{ route('password.edit') }}" class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{ $user->name }}</span>
                <span class="block text-xs text-slate-500">Changer mon mot de passe</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-red-700">Déconnexion</button>
            </form>
        </div>
        @foreach ($groups as $label => $items)
            <p class="px-1 pb-2 pt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
            <div class="grid grid-cols-3 gap-2">
                @foreach ($items as [$route, $params, $text, $pattern])
                    <a href="{{ route($route, $params) }}"
                       @class(['flex flex-col items-center justify-center gap-1.5 rounded-xl bg-white px-1 py-3 text-center text-xs shadow-sm ring-1',
                               'ring-emerald-500 font-semibold text-emerald-800' => request()->routeIs($pattern),
                               'ring-slate-900/5' => ! request()->routeIs($pattern)])>
                        <svg class="size-6 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icons[$route] ?? 'M4 12h16' }}"/></svg>
                        <span class="leading-tight">{{ $short[$text] ?? $text }}</span>
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    {{-- Téléphone : onglets du bas, à portée de pouce --}}
    @php
        $tabs = array_values(array_filter([
            $canPos ? ['pos', $q, 'Caisse', 'pos', 'M3 7h18M5 7l1 13h12l1-13M9 7V5a3 3 0 0 1 6 0v2'] : null,
            $can('sale.refund') ? ['returns', $q, 'Retours', 'returns', 'M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3'] : null,
            $can('catalogue.view') ? ['catalogue.index', [], 'Catalogue', 'catalogue.*', 'M4 6h16M4 12h16M4 18h10'] : null,
        ]));
        if ($alertCount !== null) $tabs[] = ['alerts.index', [], 'Alertes', 'alerts.*', 'M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0'];
    @endphp
    <template x-teleport="body">
    <nav data-tour-order="3" data-tour-title="Raccourcis" data-tour="Sur téléphone, les écrans les plus utilisés sont en bas ; Menu ouvre toutes les autres fonctions." class="bottom-tabs fixed inset-x-0 bottom-0 z-40 flex border-t border-slate-200 bg-white text-slate-500 lg:hidden"
         style="padding-bottom: env(safe-area-inset-bottom)" aria-label="Navigation principale">
        @foreach ($tabs as [$route, $params, $text, $pattern, $icon])
            <a href="{{ route($route, $params) }}" @class(['relative flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px]', 'font-semibold text-emerald-700' => request()->routeIs($pattern)])>
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                {{ $text }}
                @if ($route === 'alerts.index' && $alertCount)
                    <span class="absolute start-1/2 top-1 ms-2 rounded-full bg-red-600 px-1.5 text-[10px] font-semibold text-white">{{ $alertCount }}</span>
                @endif
            </a>
        @endforeach
        <button type="button" @click.stop="drawer = ! drawer; window.scrollTo({ top: 0 })" :class="drawer && 'font-semibold text-emerald-700'"
                class="flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px]">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            Menu
        </button>
    </nav>
    </template>
</header>
@endif
