@php
    $input = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none';
@endphp

<div>
    <x-admin-nav />

    <main class="mx-auto max-w-3xl space-y-4 p-4">
        <h1 class="text-xl font-semibold">Paramètres de la plateforme</h1>

        @if ($message) <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $message }}</p> @endif
        @if ($error) <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $error }}</p> @endif

        <form wire:submit="save" class="space-y-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
            <div>
                <h2 class="font-semibold">Lecture des bordereaux — API Claude</h2>
                <p class="text-sm text-slate-500">Une seule clé pour toutes les pharmacies. Elle est chiffrée en base et n'est jamais réaffichée.</p>
            </div>

            <div class="text-sm">
                Clé actuelle :
                @if ($keyHint)
                    <span class="font-mono font-semibold">{{ $keyHint }}</span>
                    <span class="text-slate-500">({{ $keySource === 'console' ? 'enregistrée ici' : 'fichier .env du serveur' }})</span>
                    @if ($keySource === 'console')
                        <button type="button" wire:click="removeKey" wire:confirm="Supprimer la clé API enregistrée ? La lecture des bordereaux sera indisponible si le serveur n'en a pas d'autre."
                                class="ms-2 text-red-700 hover:underline">Supprimer</button>
                    @endif
                @else
                    <span class="font-semibold text-amber-700">aucune — la lecture des bordereaux est désactivée</span>
                @endif
            </div>

            <label class="block">
                <span class="text-sm font-medium">{{ $keyHint ? 'Remplacer la clé' : 'Clé API' }}</span>
                <input wire:model="apiKey" type="password" autocomplete="off" placeholder="sk-ant-…" class="{{ $input }} mt-1 font-mono">
                @error('apiKey') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                <span class="mt-1 block text-xs text-slate-500">À créer sur console.anthropic.com → API Keys. Laissez vide pour garder la clé actuelle.</span>
            </label>

            <label class="block">
                <span class="text-sm font-medium">Modèle</span>
                <select wire:model="model" class="{{ $input }} mt-1">
                    @foreach (\App\Livewire\Admin\PlatformSettings::MODELS as $id => [$label, $in, $out])
                        <option value="{{ $id }}">{{ $label }} · {{ rtrim(rtrim(number_format($in, 2, ',', ''), '0'), ',') }} $ / {{ rtrim(rtrim(number_format($out, 2, ',', ''), '0'), ',') }} $ par million de tokens</option>
                    @endforeach
                </select>
                @error('model') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
            </label>

            <div class="flex flex-wrap gap-2">
                <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Enregistrer</button>
                <button type="button" wire:click="test" wire:loading.attr="disabled" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
                    <span wire:loading.remove wire:target="test">Tester la connexion</span>
                    <span wire:loading wire:target="test">Test en cours…</span>
                </button>
            </div>
        </form>

        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
            <h2 class="font-semibold">Consommation — 30 derniers jours</h2>
            <dl class="mt-2 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-xs text-slate-500">Bordereaux lus</dt><dd class="text-xl font-semibold">{{ $usage['reads'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Pages</dt><dd class="text-xl font-semibold">{{ $usage['pages'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Pharmacies</dt><dd class="text-xl font-semibold">{{ $usage['pharmacies'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Coût estimé</dt><dd class="text-xl font-semibold">{{ number_format($usage['cost'], 2, ',', ' ') }} $</dd></div>
            </dl>
            <p class="mt-2 text-xs text-slate-500">Estimation d'après les tokens enregistrés à chaque lecture ; la facture exacte est sur console.anthropic.com.</p>
        </section>
    </main>
</div>
