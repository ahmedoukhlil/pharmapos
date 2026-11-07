@php $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm'; @endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-4xl space-y-4 p-4">
        <div class="flex items-center">
            <h1 class="text-xl font-semibold">Modes de paiement</h1>
            @if ($editing === null)
                <button wire:click="edit" class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Ajouter un mode</button>
            @endif
        </div>

        <p class="text-sm text-slate-600">Les modes actifs apparaissent en caisse et aux retours, dans cet ordre. Un mode déjà utilisé ne peut pas être supprimé : désactivez-le.</p>

        @error('method') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p> @enderror

        @if ($editing !== null)
            <form wire:submit="save" class="space-y-3 rounded-lg bg-white p-4 shadow-sm">
                <label class="block">
                    <span class="text-sm font-medium">Libellé *</span>
                    <input wire:model="form.name" placeholder="ex. Sedad, Click, Carte bancaire" class="{{ $input }}" autofocus>
                    @error('name') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                </label>
                <fieldset class="space-y-1 text-sm">
                    <legend class="font-medium">Type</legend>
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="form.is_cash" value="1" @checked($form['is_cash'])> Espèces - rendu monnaie autorisé, compté au fond de caisse</label>
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="form.is_cash" value="0" @checked(! $form['is_cash'])> Électronique (mobile money, carte, virement) - montant exact, pas de rendu</label>
                    @error('is_cash') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                </fieldset>
                @unless ($form['is_cash'])
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="form.reference_allowed"> Saisir une référence de transaction à l'encaissement
                    </label>
                @endunless
                <div class="flex gap-2">
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
                    <button type="button" wire:click="$set('editing', null)" class="rounded-lg px-3 py-2 text-sm">Annuler</button>
                </div>
            </form>
        @endif

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="w-20 px-3 py-2 text-start">Ordre</th>
                        <th class="px-3 py-2 text-start">Mode</th>
                        <th class="px-3 py-2 text-start">Type</th>
                        <th class="px-3 py-2 text-start">Statut</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($methods as $m)
                        <tr wire:key="{{ $m->id }}" @class(['text-slate-400' => ! $m->is_active])>
                            <td class="px-3 py-2">
                                <button wire:click="move('{{ $m->id }}', -1)" @disabled($loop->first) class="rounded px-1.5 hover:bg-slate-100 disabled:opacity-30" title="Monter">▲</button>
                                <button wire:click="move('{{ $m->id }}', 1)" @disabled($loop->last) class="rounded px-1.5 hover:bg-slate-100 disabled:opacity-30" title="Descendre">▼</button>
                            </td>
                            <td class="px-3 py-2 font-medium">{{ $m->name }} <span class="font-mono text-xs text-slate-400">{{ $m->code }}</span></td>
                            <td class="px-3 py-2">{{ $m->is_cash ? 'Espèces' : 'Électronique'.($m->reference_allowed ? ' · référence' : '') }}</td>
                            <td class="px-3 py-2">
                                <span @class(['rounded-full px-2 text-xs font-semibold', 'bg-emerald-100 text-emerald-800' => $m->is_active, 'bg-slate-100 text-slate-500' => ! $m->is_active])>{{ $m->is_active ? 'Actif' : 'Inactif' }}</span>
                            </td>
                            <td class="space-x-3 whitespace-nowrap px-3 py-2 text-end">
                                <button wire:click="edit('{{ $m->id }}')" class="text-emerald-800 hover:underline">Modifier</button>
                                @if ($m->is_active)
                                    <button wire:click="toggle('{{ $m->id }}')" wire:confirm="Désactiver « {{ $m->name }} » ? Il ne sera plus proposé en caisse." class="text-slate-600 hover:underline">Désactiver</button>
                                @else
                                    <button wire:click="toggle('{{ $m->id }}')" class="text-emerald-800 hover:underline">Activer</button>
                                @endif
                                @unless (isset($used[$m->id]))
                                    <button wire:click="delete('{{ $m->id }}')" wire:confirm="Supprimer « {{ $m->name }} » ?" class="text-red-700 hover:underline">Supprimer</button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </main>
</div>
