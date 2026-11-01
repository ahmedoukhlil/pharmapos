@php
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none';
    $methodLabels = collect(\App\Services\Settings\PharmacySetupService::PAYMENT_METHODS)->map(fn ($m) => $m[0]);
@endphp

<main class="flex min-h-screen items-start justify-center p-4 sm:items-center">
    <div class="w-full max-w-2xl space-y-5">
        <div class="text-center">
            <img src="/images/logo.png" alt="PharmaPOS — Gestion de Pharmacie" class="mx-auto h-28 w-28">
            <h1 class="mt-2 text-xl font-semibold">Bienvenue ! Installons votre officine.</h1>
            <p class="text-sm text-slate-500">Quatre étapes, environ deux minutes. Tout pourra être modifié ensuite.</p>
        </div>

        <ol class="flex items-center gap-2 text-xs sm:text-sm">
            @foreach (\App\Livewire\Setup\SetupWizard::STEPS as $n => $label)
                <li class="flex flex-1 items-center gap-2">
                    <span @class(['flex size-7 shrink-0 items-center justify-center rounded-full font-semibold',
                        'bg-emerald-700 text-white' => $n === $step, 'bg-emerald-100 text-emerald-800' => $n < $step, 'bg-slate-200 text-slate-500' => $n > $step])>
                        {{ $n < $step ? '✓' : $n }}
                    </span>
                    <span @class(['hidden sm:inline', 'font-semibold text-slate-900' => $n === $step, 'text-slate-500' => $n !== $step])>{{ $label }}</span>
                    @unless ($loop->last) <span class="h-px flex-1 bg-slate-200"></span> @endunless
                </li>
            @endforeach
        </ol>

        <form wire:submit="{{ $step < 4 ? 'next' : 'install' }}" class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-900/5">
            @if ($step === 1)
                <h2 class="font-semibold">Votre officine</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (['name' => "Nom de l'officine *", 'legal_name' => 'Raison sociale', 'nif' => 'NIF', 'phone' => 'Téléphone', 'address' => 'Adresse', 'city' => 'Ville'] as $f => $l)
                        <label class="block">
                            <span class="text-sm font-medium">{{ $l }}</span>
                            <input wire:model="pharmacy.{{ $f }}" class="{{ $input }}" @if ($f === 'name') autofocus placeholder="ex. Pharmacie Ennour" @endif>
                            @error("pharmacy.$f") <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500">Ces informations figurent sur les tickets de caisse.</p>
            @elseif ($step === 2)
                <h2 class="font-semibold">Compte du propriétaire</h2>
                <p class="text-sm text-slate-500">Ce compte a tous les droits : il crée ensuite les comptes des pharmaciens et caissiers.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (['name' => 'Nom complet *', 'username' => 'Identifiant de connexion *', 'phone' => 'Téléphone', 'password' => 'Mot de passe *', 'password_confirmation' => 'Confirmer le mot de passe *'] as $f => $l)
                        <label @class(['block', 'sm:col-start-1' => $f === 'password'])>
                            <span class="text-sm font-medium">{{ $l }}</span>
                            <input wire:model="owner.{{ $f }}" @if (str_starts_with($f, 'password')) type="password" autocomplete="new-password" @endif class="{{ $input }}">
                            @error("owner.$f") <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500">Au moins 8 caractères, avec des lettres et des chiffres. Notez-le en lieu sûr.</p>
            @elseif ($step === 3)
                <h2 class="font-semibold">Caisses</h2>
                <div class="space-y-2">
                    @foreach ($registers as $i => $r)
                        <div wire:key="r-{{ $i }}" class="flex items-center gap-2">
                            <span class="w-10 font-mono text-xs text-slate-400">{{ sprintf('C%02d', $i + 1) }}</span>
                            <input wire:model="registers.{{ $i }}" class="{{ $input }} mt-0">
                            @if (count($registers) > 1)
                                <button type="button" wire:click="removeRegister({{ $i }})" class="rounded px-2 py-1 text-slate-400 hover:bg-red-50 hover:text-red-700" title="Retirer">✕</button>
                            @endif
                        </div>
                        @error("registers.$i") <span class="ms-12 text-xs text-red-700">{{ $message }}</span> @enderror
                    @endforeach
                    <button type="button" wire:click="addRegister" class="text-sm text-emerald-800 hover:underline">+ Ajouter une caisse</button>
                </div>

                <h2 class="pt-2 font-semibold">Modes de paiement acceptés</h2>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($methodLabels as $code => $label)
                        <label @class(['flex items-center gap-2 rounded-lg border px-3 py-2 text-sm', 'border-emerald-300 bg-emerald-50' => $methods[$code] ?? false, 'border-slate-200' => ! ($methods[$code] ?? false)])>
                            <input type="checkbox" wire:model.live="methods.{{ $code }}" @disabled($code === 'CASH')> {{ $label }}
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500">Les espèces sont toujours acceptées. D'autres modes pourront être ajoutés dans Gestion › Modes de paiement.</p>
            @else
                <h2 class="font-semibold">Vérification</h2>
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Officine</dt><dd class="text-end font-medium">{{ $pharmacy['name'] }}{{ $pharmacy['city'] ? ', '.$pharmacy['city'] : '' }}</dd></div>
                    <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Propriétaire</dt><dd class="text-end font-medium">{{ $owner['name'] }} <span class="font-mono text-xs text-slate-500">({{ $owner['username'] }})</span></dd></div>
                    <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Caisses</dt><dd class="text-end font-medium">{{ implode(', ', $registers) }}</dd></div>
                    <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Paiements</dt><dd class="text-end font-medium">{{ $methodLabels->filter(fn ($l, $c) => $c === 'CASH' || ($methods[$c] ?? false))->implode(', ') }}</dd></div>
                    <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Emplacements</dt><dd class="text-end font-medium">Rayon comptoir, Réserve, Quarantaine</dd></div>
                </dl>
                <p class="text-sm text-slate-500">Après l'installation, vous serez connecté et pourrez importer votre catalogue de produits.</p>
            @endif

            <div class="flex items-center gap-2 pt-2">
                @if ($step > 1)
                    <button type="button" wire:click="back" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">← Retour</button>
                @endif
                <button wire:loading.attr="disabled" class="ms-auto rounded-lg bg-emerald-700 px-5 py-2 font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                    {{ $step < 4 ? 'Continuer →' : "Installer l'officine" }}
                </button>
            </div>
        </form>
    </div>
</main>
