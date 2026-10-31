@props(['register' => null])

<header class="flex items-center gap-4 bg-emerald-800 px-4 py-2 text-white print:hidden">
    <span class="font-semibold">PharmaPOS</span>
    <nav class="flex gap-1 text-sm">
        @php $q = $register ? ['caisse' => $register] : []; @endphp
        <a href="{{ route('pos', $q) }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('pos')])>Caisse</a>
        <a href="{{ route('returns', $q) }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('returns')])>Retours</a>
        <a href="{{ route('pos.close', $q) }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('pos.close')])>Clôture</a>
        <a href="{{ route('catalogue.index') }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('catalogue.*')])>Catalogue</a>
        <a href="{{ route('receipts.index') }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('receipts.*')])>Réceptions</a>
        <a href="{{ route('suppliers.index') }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('suppliers.*')])>Fournisseurs</a>
        <a href="{{ route('inventories.index') }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('inventories.*')])>Inventaires</a>
        <a href="{{ route('sessions.index') }}" @class(['rounded px-2 py-1 hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('sessions.*')])>Sessions</a>
    </nav>
    @if (auth()->user()->hasPermission('stock.view'))
        @php
            $pharmacyId = auth()->user()->pharmacy_id;
            $alertCount = cache()->remember("alerts.count.$pharmacyId", 60, fn () => app(\App\Services\Alerts\AlertService::class)->count($pharmacyId));
        @endphp
        <a href="{{ route('alerts.index') }}" @class(['rounded px-2 py-1 text-sm hover:bg-emerald-700', 'bg-emerald-900' => request()->routeIs('alerts.*')])>
            Alertes @if ($alertCount) <span class="ms-1 rounded-full bg-red-600 px-2 text-xs font-semibold">{{ $alertCount }}</span> @endif
        </a>
    @endif
    {{ $slot }}
    <span class="ms-auto text-sm">{{ auth()->user()->name }}</span>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="rounded px-2 py-1 text-sm hover:bg-emerald-700">Déconnexion</button>
    </form>
</header>
