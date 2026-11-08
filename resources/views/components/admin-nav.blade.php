{{-- En-tête de la console SaaS (super-administrateur) --}}
<header x-data="{ drawer: false }" @click.outside="drawer = false" class="sticky top-0 z-40 bg-slate-900 text-white print:hidden sm:relative" style="padding-top: env(safe-area-inset-top)">
    <div class="flex items-center gap-2 px-3 py-2 lg:gap-3 lg:px-4">
        <a href="{{ route('admin.pharmacies') }}" class="flex shrink-0 items-center gap-2 font-semibold">
            <img src="/images/icon-64.png" alt="" class="size-8 rounded-md bg-white p-0.5">
            <span>PharmaPOS <span class="font-normal text-slate-400">Admin</span></span>
        </a>
        <nav class="ms-2 hidden gap-1 text-sm sm:flex">
            <a href="{{ route('admin.pharmacies') }}" @class(['rounded px-2 py-1 hover:bg-slate-700', 'bg-slate-700' => request()->routeIs('admin.pharmacies*')])>Pharmacies</a>
            <a href="{{ route('admin.settings') }}" @class(['rounded px-2 py-1 hover:bg-slate-700', 'bg-slate-700' => request()->routeIs('admin.settings')])>Paramètres</a>
            <a href="{{ route('admin.mail') }}" @class(['rounded px-2 py-1 hover:bg-slate-700', 'bg-slate-700' => request()->routeIs('admin.mail')])>E-mails</a>
            <a href="{{ route('backups.index') }}" @class(['rounded px-2 py-1 hover:bg-slate-700', 'bg-slate-700' => request()->routeIs('backups.*')])>Sauvegardes</a>
        </nav>
        <div class="ms-auto hidden items-center gap-3 sm:flex">
            <a href="{{ route('password.edit') }}" class="rounded px-2 py-1 text-sm hover:bg-slate-700" title="Mon mot de passe">{{ auth()->user()->name }}</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded px-2 py-1 text-sm hover:bg-slate-700">Déconnexion</button>
            </form>
        </div>
        <button type="button" @click="drawer = ! drawer" aria-label="Menu" class="ms-auto rounded p-2 hover:bg-slate-700 sm:hidden">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
    </div>
    <nav x-show="drawer" x-cloak class="absolute inset-x-0 top-full z-50 bg-white text-slate-800 shadow-lg sm:hidden">
        <a href="{{ route('admin.pharmacies.create') }}" class="block px-4 py-3 font-semibold text-emerald-800">+ Nouvelle pharmacie</a>
        <a href="{{ route('admin.pharmacies') }}" class="block px-4 py-3">Pharmacies</a>
        <a href="{{ route('admin.settings') }}" class="block px-4 py-3">Paramètres</a>
        <a href="{{ route('admin.mail') }}" class="block px-4 py-3">E-mails</a>
        <a href="{{ route('backups.index') }}" class="block px-4 py-3">Sauvegardes</a>
        <a href="{{ route('password.edit') }}" class="block px-4 py-3">Mon mot de passe</a>
        <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-200 px-4 py-3">
            @csrf
            <button class="rounded-lg bg-slate-100 px-3 py-2 text-sm">Déconnexion</button>
        </form>
    </nav>
</header>
