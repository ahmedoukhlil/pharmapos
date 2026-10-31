@php
    $s = $session;
    $statuses = ['counting' => 'Comptage en cours', 'review' => 'En revue', 'approved' => 'Approuvé', 'cancelled' => 'Annulé', 'draft' => 'Brouillon'];
    $counting = $s->status === 'counting' && $this->can['count'];
    $showTheoretical = $this->can['approve'] && $s->status !== 'counting';
    $field = $this->recountRequested ? 'recount_quantity' : 'counted_quantity';
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">Inventaire {{ $s->number }}</h1>
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">{{ $statuses[$s->status] }}</span>
            <span class="text-sm text-slate-500">Stock figé le {{ $s->snapshot_at->format('d/m/Y H:i') }} · {{ $s->location?->name ?? 'tous emplacements' }}</span>
            @if ($counting)
                <livewire:scanner.scanner-panel mode="inventory" :context-id="$s->id" :key="'scanner-'.$s->id" />
            @endif
            <a href="{{ route('inventories.index') }}" class="ms-auto text-sm text-slate-600 hover:underline">← Inventaires</a>
        </div>

        @if ($error)
            <p class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">{{ $error }}</p>
        @endif
        @if ($message)
            <p class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800" role="status">{{ $message }}</p>
        @endif
        @if ($s->status === 'counting' && $this->recountRequested)
            <p class="rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-900">Recomptage demandé : seules les lignes en écart sont affichées.</p>
        @endif

        @if ($counting)
            <div class="flex flex-wrap items-center gap-3">
                <input x-init="$nextTick(() => $el.focus())"
                       x-on:keydown.enter.prevent="$wire.scan($el.value); $el.value = ''"
                       placeholder="Scanner un produit pour afficher ses lots" autocomplete="off" aria-label="Scanner un produit"
                       class="min-w-64 flex-1 rounded-lg border border-slate-300 bg-white px-4 py-2 shadow-sm">
                @if ($presentationFilter)
                    <button wire:click="clearFilter" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-white">Tout afficher</button>
                @endif
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="onlyUncounted"> Non comptées seulement
                </label>
                <span class="text-sm text-slate-600">{{ $this->progress['counted'] }} / {{ $this->progress['total'] }} lignes comptées</span>
            </div>
        @endif

        <section class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">Produit</th>
                        <th class="px-3 py-2 text-start">Lot</th>
                        <th class="px-3 py-2 text-start">Emplacement</th>
                        @if ($showTheoretical) <th class="px-3 py-2 text-end">Théorique</th> @endif
                        <th class="px-3 py-2 text-end">Compté (unités)</th>
                        @if ($showTheoretical)
                            <th class="px-3 py-2 text-end">Écart</th>
                            <th class="px-3 py-2 text-end">Valeur</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->lines as $line)
                        @php $perPack = $line->presentation->units_per_pack; $final = $line->recount_quantity ?? $line->counted_quantity; @endphp
                        <tr wire:key="l-{{ $line->id }}" @class(['bg-red-50/60' => $showTheoretical && $line->variance < 0, 'bg-amber-50/60' => $showTheoretical && $line->variance > 0])>
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ $line->presentation->medicine->brand_name }} {{ $line->presentation->medicine->strength }}</span>
                                <span class="block text-xs text-slate-500">{{ $line->presentation->label }}</span>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ $line->batch->lot_number }} <span class="text-slate-500">· {{ $line->batch->expiry_date->format('m/Y') }}</span>
                                @if ($line->batch->status !== 'available')
                                    <span class="ms-1 rounded bg-amber-100 px-1 font-sans text-amber-900">{{ $line->batch->status }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $line->location->name }}</td>
                            @if ($showTheoretical)
                                <td class="px-3 py-2 text-end tabular-nums">{{ $line->theoretical_quantity }}</td>
                            @endif
                            <td class="px-3 py-2 text-end">
                                @if ($counting)
                                    <input type="number" min="0" value="{{ $line->$field }}"
                                           wire:change="count('{{ $line->id }}', $event.target.value)"
                                           class="w-20 rounded border border-slate-200 px-1 py-1 text-end tabular-nums"
                                           aria-label="Quantité comptée {{ $line->batch->lot_number }}">
                                    @if ($perPack > 1)
                                        <span class="block text-xs text-slate-500">{{ $perPack }} / boîte</span>
                                    @endif
                                @else
                                    <span class="tabular-nums">{{ $final ?? '—' }}</span>
                                    @if ($line->recount_quantity !== null)
                                        <span class="block text-xs text-slate-500">recompté (1er : {{ $line->counted_quantity }})</span>
                                    @endif
                                @endif
                            </td>
                            @if ($showTheoretical)
                                <td class="px-3 py-2 text-end font-medium tabular-nums">{{ $line->variance > 0 ? '+' : '' }}{{ $line->variance ?? '—' }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $line->variance_value !== null ? $money($line->variance_value) : '—' }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-slate-500">Aucune ligne.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        @if ($this->missingBatches->isNotEmpty())
            <section class="rounded-lg border border-dashed border-slate-300 bg-white p-3 text-sm">
                <p class="mb-2 text-slate-600">Lot trouvé en rayon mais absent de la feuille ?</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->missingBatches as $b)
                        <button wire:click="addBatch('{{ $b->id }}')" class="rounded-full border border-slate-300 px-3 py-1 font-mono text-xs hover:bg-slate-50">
                            + {{ $b->lot_number }} · {{ $b->expiry_date->format('m/Y') }}
                        </button>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Actions --}}
        @if ($counting)
            <div class="flex flex-wrap items-center gap-3 rounded-lg bg-white p-4 shadow-sm">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="uncountedAsZero"> Compter à zéro les lignes non comptées
                </label>
                <button wire:click="submit" wire:confirm="Terminer le comptage et calculer les écarts ?"
                        class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Terminer le comptage</button>
            </div>
        @endif

        @if ($s->status === 'review')
            @if ($this->can['approve'])
                @php $lines = $s->lines()->get(); @endphp
                <div class="space-y-3 rounded-lg bg-white p-4 shadow-sm">
                    <p class="text-sm">
                        {{ $lines->where('variance', '!=', 0)->count() }} ligne(s) en écart ·
                        valeur nette <span class="font-semibold tabular-nums">{{ $money($lines->sum('variance_value')) }} MRU</span>
                    </p>
                    <textarea wire:model="comment" rows="2" placeholder="Commentaire" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                    <div class="flex flex-wrap gap-2">
                        <button wire:click="cancel" wire:confirm="Annuler l'inventaire sans ajuster le stock ?" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">Annuler l'inventaire</button>
                        <button wire:click="recount" class="ms-auto rounded-lg border border-amber-400 px-3 py-2 text-sm text-amber-900 hover:bg-amber-50">Demander un recomptage</button>
                        <button wire:click="approve" wire:confirm="Approuver et ajuster le stock des lignes en écart ?"
                                class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Approuver et ajuster</button>
                    </div>
                </div>
            @else
                <p class="rounded-lg bg-slate-50 px-4 py-2 text-sm text-slate-600">Comptage transmis : en attente de validation par un responsable.</p>
            @endif
        @endif
    </main>

    <x-reauth-modal :pending="$reauth" />
</div>
