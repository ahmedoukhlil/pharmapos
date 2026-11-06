@php
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm';
    [$kind, $editId] = $editing ?? [null, null];
    $sellable = $locations->where('is_active', true)->where('is_sellable', true);
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-5xl space-y-6 p-4">
        <h1 class="text-xl font-semibold">Caisses et emplacements</h1>

        @foreach (['register', 'location'] as $err)
            @error($err) <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p> @enderror
        @endforeach

        {{-- Formulaire (caisse ou emplacement) --}}
        @if ($kind)
            <form wire:submit="save" class="grid gap-3 rounded-lg bg-white p-4 shadow-sm ring-2 ring-emerald-200 sm:grid-cols-3">
                <h2 class="font-semibold sm:col-span-3">
                    {{ $kind === 'register' ? ($editId ? 'Modifier la caisse' : 'Nouvelle caisse') : ($editId ? "Modifier l'emplacement" : 'Nouvel emplacement') }}
                </h2>
                <label class="block">
                    <span class="text-sm font-medium">Nom *</span>
                    <input wire:model="form.name" class="{{ $input }}" autofocus>
                    @error('name') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                </label>
                @if ($kind === 'register')
                    <label class="block">
                        <span class="text-sm font-medium">Vend le stock de</span>
                        <select wire:model="form.location_id" class="{{ $input }}">
                            @foreach ($sellable as $l) <option value="{{ $l->id }}">{{ $l->name }}</option> @endforeach
                        </select>
                        @error('location_id') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Imprimante reçus</span>
                        <select wire:model="form.printer_width" class="{{ $input }}">
                            <option value="80">80 mm (standard)</option>
                            <option value="58">58 mm (petite)</option>
                        </select>
                    </label>
                @else
                    @php $isQuarantine = ($form['type'] ?? '') === 'quarantine'; $locked = $editId && $locations->firstWhere('id', $editId)?->type === 'quarantine'; @endphp
                    <label class="block">
                        <span class="text-sm font-medium">Type</span>
                        <select wire:model.live="form.type" @disabled($locked) class="{{ $input }} disabled:bg-slate-50">
                            @foreach ($types as $k => $v) <option value="{{ $k }}">{{ $v }}</option> @endforeach
                        </select>
                        @error('type') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm">
                        <input type="checkbox" wire:model="form.is_sellable" @disabled($isQuarantine)>
                        {{ $isQuarantine ? 'Jamais vendable (quarantaine)' : 'Vendable (stock proposé en caisse)' }}
                    </label>
                    @error('is_sellable') <span class="text-xs text-red-700 sm:col-span-3">{{ $message }}</span> @enderror
                @endif
                <div class="flex gap-2 sm:col-span-3">
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
                    <button type="button" wire:click="$set('editing', null)" class="rounded-lg px-3 py-2 text-sm">Annuler</button>
                </div>
            </form>
        @endif

        {{-- Caisses --}}
        <section class="space-y-2">
            <div class="flex items-center">
                <div>
                    <h2 class="font-semibold">Caisses</h2>
                    <p class="text-sm text-slate-500">Chaque poste d'encaissement, avec son fond de caisse et sa numérotation de reçus.</p>
                </div>
                <button wire:click="editRegister" class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Ajouter une caisse</button>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($registers as $r)
                    <div wire:key="r-{{ $r->id }}" @class(['rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5', 'opacity-60' => ! $r->is_active])>
                        <div class="flex items-start gap-2">
                            <div>
                                <p class="font-semibold">{{ $r->name }}</p>
                                <p class="font-mono text-xs text-slate-400">{{ $r->code }}</p>
                            </div>
                            @if (isset($openSessions[$r->id]))
                                <span class="ms-auto rounded-full bg-emerald-100 px-2 text-xs font-semibold text-emerald-800">Session ouverte</span>
                            @elseif (! $r->is_active)
                                <span class="ms-auto rounded-full bg-slate-100 px-2 text-xs text-slate-500">Inactive</span>
                            @endif
                        </div>
                        <dl class="mt-3 space-y-1 text-sm">
                            <div class="flex justify-between"><dt class="text-slate-500">Stock vendu depuis</dt><dd>{{ $r->location->name }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Imprimante</dt><dd>{{ $r->printer_width }} mm</dd></div>
                        </dl>
                        <div class="mt-3 flex gap-3 border-t border-slate-100 pt-2 text-sm">
                            <button wire:click="editRegister('{{ $r->id }}')" class="text-emerald-800 hover:underline">Modifier</button>
                            @if ($r->is_active)
                                <button wire:click="toggleRegister('{{ $r->id }}')" wire:confirm="Désactiver « {{ $r->name }} » ? Elle ne sera plus proposée en caisse." class="ms-auto text-red-700 hover:underline">Désactiver</button>
                            @else
                                <button wire:click="toggleRegister('{{ $r->id }}')" class="ms-auto text-emerald-800 hover:underline">Réactiver</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Emplacements --}}
        <section class="space-y-2">
            <div class="flex items-center">
                <div>
                    <h2 class="font-semibold">Emplacements de stock</h2>
                    <p class="text-sm text-slate-500">Seul le stock des emplacements vendables est proposé en caisse.</p>
                </div>
                <button wire:click="editLocation" class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Ajouter un emplacement</button>
            </div>
            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2 text-start">Emplacement</th>
                            <th class="px-3 py-2 text-start">Type</th>
                            <th class="px-3 py-2 text-start">Vente</th>
                            <th class="px-3 py-2 text-end">Stock</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($locations as $l)
                            @php $s = $stock[$l->id] ?? null; @endphp
                            <tr wire:key="l-{{ $l->id }}" @class(['text-slate-400' => ! $l->is_active])>
                                <td class="px-3 py-2"><span class="font-medium">{{ $l->name }}</span> <span class="font-mono text-xs text-slate-400">{{ $l->code }}</span> @unless ($l->is_active) <span class="rounded-full bg-slate-100 px-2 text-xs">inactif</span> @endunless</td>
                                <td class="px-3 py-2">{{ $types[$l->type] ?? $l->type }}</td>
                                <td class="px-3 py-2">
                                    @if ($l->is_sellable) <span class="rounded-full bg-emerald-50 px-2 text-xs font-semibold text-emerald-700">Vendable</span>
                                    @else <span class="rounded-full bg-amber-50 px-2 text-xs font-semibold text-amber-700">Bloqué</span> @endif
                                </td>
                                <td class="px-3 py-2 text-end tabular-nums text-slate-600">{{ $s ? $s->batches.' lot(s) · '.number_format($s->units, 0, ',', ' ').' unités' : 'Vide' }}</td>
                                <td class="space-x-3 whitespace-nowrap px-3 py-2 text-end">
                                    <button wire:click="editLocation('{{ $l->id }}')" class="text-emerald-800 hover:underline">Modifier</button>
                                    @if ($l->is_active)
                                        <button wire:click="toggleLocation('{{ $l->id }}')" wire:confirm="Désactiver l'emplacement « {{ $l->name }} » ?" class="text-red-700 hover:underline">Désactiver</button>
                                    @else
                                        <button wire:click="toggleLocation('{{ $l->id }}')" class="text-emerald-800 hover:underline">Réactiver</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
