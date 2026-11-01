@props(['register' => null])

@php
    $user = auth()->user();
    $q = $register ? ['caisse' => $register] : [];
    // Menus regroupés : [libellé => [[route, paramètres, libellé, motif actif, visible]]]
    $groups = [
        'Ventes' => [
            ['pos', $q, 'Caisse', 'pos', true],
            ['returns', $q, 'Retours', 'returns', true],
            ['pos.close', $q, 'Clôture', 'pos.close', true],
            ['sessions.index', [], 'Sessions', 'sessions.*', true],
        ],
        'Achats' => [
            ['orders.index', [], 'Commandes', 'orders.*', true],
            ['receipts.index', [], 'Réceptions', 'receipts.*', true],
            ['invoices.index', [], 'Factures', 'invoices.*', $user->hasPermission('purchase.invoice')],
            ['suppliers.index', [], 'Fournisseurs', 'suppliers.*', true],
        ],
        'Stock' => [
            ['catalogue.index', [], 'Catalogue', 'catalogue.*', true],
            ['inventories.index', [], 'Inventaires', 'inventories.*', true],
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
        class="relative z-40 bg-emerald-800 text-white print:hidden">
    <div class="flex items-center gap-2 px-3 py-2 lg:gap-3 lg:px-4">
        <a href="{{ route('pos', $q) }}" class="flex shrink-0 items-center gap-2 font-semibold">
            <img src="/images/icon-64.png" alt="" class="size-8 rounded-md bg-white p-0.5">
            <span class="hidden sm:inline">PharmaPOS</span>
        </a>

        <nav class="hidden gap-1 whitespace-nowrap text-sm lg:flex">
            <a href="{{ route('pos', $q) }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('pos')])>Caisse</a>
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
            <a href="{{ route('alerts.index') }}" title="Alertes" @class(['flex shrink-0 items-center rounded px-2 py-1 text-sm hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('alerts.*')])>
                <svg class="size-5 lg:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                <span class="hidden lg:inline">Alertes</span>
                @if ($alertCount) <span class="ms-1 rounded-full bg-red-600 px-2 text-xs font-semibold">{{ $alertCount }}</span> @endif
            </a>
        @endif

        <div class="ms-auto flex min-w-0 items-center gap-2 lg:gap-3">
            {{ $slot }}
            <a href="{{ route('password.edit') }}" class="hidden whitespace-nowrap rounded px-2 py-1 text-sm hover:bg-emerald-700 lg:block" title="Mon mot de passe">{{ $user->name }}</a>
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

    <nav x-show="drawer" x-cloak x-transition.opacity.duration.100ms
         class="absolute inset-x-0 top-full z-50 max-h-[calc(100dvh-3.5rem)] overflow-y-auto bg-white text-slate-800 shadow-lg lg:hidden">
        <a href="{{ route('pos', $q) }}" @class(['block px-4 py-3 font-medium', 'bg-emerald-50 text-emerald-800' => request()->routeIs('pos')])>Caisse</a>
        @foreach ($groups as $label => $items)
            <p class="bg-slate-50 px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
            @foreach ($items as [$route, $params, $text, $pattern])
                <a href="{{ route($route, $params) }}" @class(['block px-4 py-3', 'bg-emerald-50 font-semibold text-emerald-800' => request()->routeIs($pattern)])>{{ $text }}</a>
            @endforeach
        @endforeach
        <div class="flex items-center gap-2 border-t border-slate-200 px-4 py-3">
            <a href="{{ route('password.edit') }}" class="min-w-0 flex-1 truncate text-sm text-slate-600">{{ $user->name }} · mot de passe</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-lg bg-slate-100 px-3 py-2 text-sm">Déconnexion</button>
            </form>
        </div>
    </nav>
</header>
