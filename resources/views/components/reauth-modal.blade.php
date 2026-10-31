@props(['pending' => null])

@if ($pending)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 print:hidden" role="dialog" aria-modal="true" aria-labelledby="reauth-title"
         x-on:keydown.escape.window="$wire.cancelReauth()">
        <form wire:submit="confirmReauth" class="w-full max-w-sm space-y-3 rounded-lg bg-white p-5 shadow-xl">
            <h2 id="reauth-title" class="font-semibold">Confirmez votre identité</h2>
            <p class="text-sm text-slate-600">Opération sensible : <strong>{{ $pending['label'] }}</strong>. Saisissez votre mot de passe pour continuer.</p>
            <input type="password" wire:model="reauthPassword" autocomplete="current-password" x-init="$nextTick(() => $el.focus())"
                   class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" aria-label="Mot de passe">
            @error('reauthPassword') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="cancelReauth" class="rounded-lg px-3 py-2 text-sm">Annuler</button>
                <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Confirmer</button>
            </div>
        </form>
    </div>
@endif
