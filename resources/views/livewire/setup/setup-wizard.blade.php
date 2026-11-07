@php
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none';
@endphp

<main class="flex min-h-screen items-start justify-center p-4 sm:items-center">
    <div class="w-full max-w-md space-y-5">
        <div class="text-center">
            <img src="/images/logo.png" alt="PharmaPOS - Gestion de Pharmacie" class="mx-auto h-28 w-28">
            <h1 class="mt-2 text-xl font-semibold">Bienvenue sur PharmaPOS</h1>
            <p class="text-sm text-slate-500">Créez le compte super-administrateur. Il gère les pharmacies clientes et leurs abonnements.</p>
        </div>

        <form wire:submit="install" class="space-y-3 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-900/5">
            @foreach (['name' => 'Nom complet', 'username' => 'Identifiant de connexion', 'password' => 'Mot de passe', 'password_confirmation' => 'Confirmer le mot de passe'] as $f => $l)
                <label class="block">
                    <span class="text-sm font-medium">{{ $l }}</span>
                    <input wire:model="admin.{{ $f }}" @if (str_starts_with($f, 'password')) type="password" autocomplete="new-password" @endif
                           @if ($f === 'name') autofocus @endif class="{{ $input }}">
                    @error("admin.$f") <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                </label>
            @endforeach
            <p class="text-xs text-slate-500">Au moins 10 caractères, avec des lettres et des chiffres. À la connexion, laissez le code pharmacie vide.</p>
            <button wire:loading.attr="disabled" class="w-full rounded-lg bg-emerald-700 px-5 py-2 font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                Créer le compte
            </button>
        </form>
    </div>
</main>
