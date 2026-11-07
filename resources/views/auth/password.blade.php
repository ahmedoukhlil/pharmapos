<x-layouts.app title="Mot de passe - PharmaPOS">
    @unless ($forced) <x-nav /> @endunless

    @php $field = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none'; @endphp

    <main class="flex items-center justify-center p-4 {{ $forced ? 'min-h-screen' : 'pt-10' }}">
        <form method="POST" action="{{ route('password.update') }}" class="w-full max-w-sm space-y-4 rounded-xl bg-white p-6 shadow">
            @csrf
            @method('PUT')
            <div>
                @if ($forced) <img src="/images/logo.png" alt="PharmaPOS" class="mx-auto mb-2 h-24 w-24"> @endif
                <h1 class="text-lg font-semibold">{{ $forced ? 'Choisissez votre mot de passe' : 'Mon mot de passe' }}</h1>
                <p class="text-sm text-slate-500">
                    {{ $forced ? 'Vous vous êtes connecté avec un mot de passe provisoire. Choisissez-en un personnel pour continuer.' : 'Au moins 8 caractères, avec des lettres et des chiffres.' }}
                </p>
            </div>

            @if (session('status')) <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p> @endif

            @foreach (['current_password' => $forced ? 'Mot de passe provisoire' : 'Mot de passe actuel', 'password' => 'Nouveau mot de passe', 'password_confirmation' => 'Confirmer le nouveau mot de passe'] as $name => $label)
                <label class="block">
                    <span class="text-sm font-medium">{{ $label }}</span>
                    <input type="password" name="{{ $name }}" required @if ($loop->first) autofocus autocomplete="current-password" @else autocomplete="new-password" @endif class="{{ $field }}">
                    @error($name) <span class="text-xs text-red-700" role="alert">{{ $message }}</span> @enderror
                </label>
            @endforeach

            <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Enregistrer</button>
        </form>
    </main>

    @if ($forced)
        <form method="POST" action="{{ route('logout') }}" class="-mt-16 text-center">
            @csrf
            <button class="text-sm text-slate-500 hover:underline">Se déconnecter</button>
        </form>
    @endif
</x-layouts.app>
