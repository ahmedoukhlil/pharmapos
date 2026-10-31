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
            ['backups.index', [], 'Sauvegardes', 'backups.*', \App\Livewire\System\BackupIndex::allowed()],
        ],
    ];
@endphp

<header class="flex items-center gap-3 whitespace-nowrap bg-emerald-800 px-4 py-2 text-white print:hidden">
    <a href="{{ route('pos', $q) }}" class="flex items-center gap-2 font-semibold">
        <img src="/images/icon-64.png" alt="" class="size-8 rounded-md bg-white p-0.5">
        PharmaPOS
    </a>
    <nav x-data="{ open: null }" @click.outside="open = null" @keydown.escape.window="open = null" class="flex gap-1 text-sm">
        <a href="{{ route('pos', $q) }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('pos')])>Caisse</a>
        @foreach ($groups as $label => $items)
            @php
                $items = array_filter($items, fn ($i) => $i[4]);
                $active = collect($items)->contains(fn ($i) => request()->routeIs($i[3]));
            @endphp
            @continue(! $items)
            <div class="relative">
                <button type="button" @click="open = open === '{{ $label }}' ? null : '{{ $label }}'" :aria-expanded="open === '{{ $label }}'"
                        @class(['flex items-center gap-1 rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => $active])>
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
    @if ($user->hasPermission('stock.view'))
        @php
            $pharmacyId = $user->pharmacy_id;
            $alertCount = cache()->remember("alerts.count.$pharmacyId", 60, fn () => app(\App\Services\Alerts\AlertService::class)->count($pharmacyId));
        @endphp
        <a href="{{ route('alerts.index') }}" @class(['rounded px-2 py-1 text-sm hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('alerts.*')])>
            Alertes @if ($alertCount) <span class="ms-1 rounded-full bg-red-600 px-2 text-xs font-semibold">{{ $alertCount }}</span> @endif
        </a>
    @endif
    <div class="ms-auto flex items-center gap-3">
        {{ $slot }}
        <span class="text-sm">{{ $user->name }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="rounded px-2 py-1 text-sm hover:bg-emerald-700">Déconnexion</button>
        </form>
    </div>
</header>
