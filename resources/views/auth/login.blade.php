<x-layouts.app title="Connexion — PharmaPOS">
    <main class="flex min-h-screen items-center justify-center p-4">
        <form method="POST" action="{{ route('login') }}" class="w-full max-w-sm space-y-4 rounded-xl bg-white p-6 shadow">
            @csrf
            <div>
                <h1><img src="/images/logo.png" alt="PharmaPOS — Gestion de Pharmacie" class="mx-auto h-40 w-40"></h1>
                <p class="text-center text-sm text-slate-500">Connectez-vous pour ouvrir la caisse.</p>
            </div>

            <label class="block">
                <span class="text-sm font-medium">Identifiant</span>
                <input name="username" value="{{ old('username') }}" autofocus autocomplete="username" required
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none">
            </label>

            <label class="block">
                <span class="text-sm font-medium">Mot de passe</span>
                <input type="password" name="password" autocomplete="current-password" required
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none">
            </label>

            @error('username')
                <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ $message }}</p>
            @enderror

            <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800 focus:ring-2 focus:ring-emerald-300 focus:outline-none">
                Se connecter
            </button>
        </form>
    </main>
</x-layouts.app>
