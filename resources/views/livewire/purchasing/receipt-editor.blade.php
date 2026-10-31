@php
    $labels = ['draft' => 'Brouillon', 'validated' => 'Validée', 'cancelled' => 'Annulée'];
    $total = collect($lines)->sum(fn ($l) => (int) $l['quantity_packs'] * (float) $l['unit_price'] * ($l['decision'] === 'refused' ? 0 : 1));
    $ro = ! $this->editable;
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold">
                Réception {{ $this->receipt?->number ?? '(nouvelle)' }}
            </h1>
            @if ($this->receipt)
                <span @class([
                    'rounded-full px-2 py-0.5 text-xs font-medium',
                    'bg-slate-100 text-slate-700' => $this->receipt->status === 'draft',
                    'bg-emerald-100 text-emerald-800' => $this->receipt->status === 'validated',
                    'bg-red-100 text-red-800' => $this->receipt->status === 'cancelled',
                ])>{{ $labels[$this->receipt->status] }}</span>
            @endif
            <a href="{{ route('receipts.index') }}" class="ms-auto text-sm text-slate-600 hover:underline">← Toutes les réceptions</a>
        </div>

        @if ($error)
            <p class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">{{ $error }}</p>
        @endif
        @if ($message)
            <p class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800" role="status">{{ $message }}</p>
        @endif

        {{-- En-tête --}}
        <section class="grid gap-3 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-3">
            <label class="block">
                <span class="text-sm font-medium">Fournisseur</span>
                <select wire:model="supplierId" @disabled($ro) class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($this->suppliers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium">Ranger dans</span>
                <select wire:model="locationId" @disabled($ro) class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($this->locations as $l)
                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium">N° bon de livraison</span>
                <input wire:model="deliveryNoteRef" @disabled($ro) class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </label>
        </section>

        @if (! $ro)
            <div class="relative">
                <label for="search" class="sr-only">Scanner ou rechercher un produit</label>
                <input id="search" wire:model.live.debounce.200ms="search"
                       x-init="$nextTick(() => $el.focus())"
                       x-on:keydown.enter.prevent="$wire.addFromSearch($el.value); $el.value = ''"
                       autocomplete="off" placeholder="Scanner ou rechercher un produit reçu"
                       class="w-full rounded-lg border border-slate-300 bg-white px-4 py-3 shadow-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none">
                @if ($this->results->isNotEmpty())
                    <ul class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                        @foreach ($this->results as $p)
                            <li wire:key="r-{{ $p->id }}">
                                <button wire:click="add('{{ $p->id }}')" class="w-full px-4 py-2 text-start hover:bg-emerald-50">
                                    <span class="font-medium">{{ $p->medicine->brand_name }} {{ $p->medicine->strength }}</span>
                                    <span class="text-xs text-slate-500">· {{ $p->label }} · {{ $p->sku }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        {{-- Lignes --}}
        <section class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">Produit</th>
                        <th class="px-3 py-2 text-start">Lot</th>
                        <th class="px-3 py-2 text-start">Péremption</th>
                        <th class="px-3 py-2 text-end">Boîtes</th>
                        <th class="px-3 py-2 text-end">Prix achat</th>
                        <th class="px-3 py-2 text-start">Décision</th>
                        <th class="w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($lines as $i => $line)
                        @php $p = $this->presentations[$line['presentation_id']] ?? null; $preview = $this->expiryPreview($line['expiry']); @endphp
                        <tr wire:key="line-{{ $i }}" @class(['bg-red-50/50' => $line['decision'] === 'refused', 'bg-amber-50/50' => $line['decision'] === 'quarantine'])>
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ $p?->medicine->brand_name }} {{ $p?->medicine->strength }}</span>
                                <span class="block text-xs text-slate-500">{{ $p?->label }}</span>
                                @if ($this->receipt?->status === 'validated' && ($batch = $this->receipt->lines[$i]->batch ?? null))
                                    <span class="text-xs text-emerald-700">→ lot {{ $batch->lot_number }} ({{ $batch->status }})</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <input wire:model.blur="lines.{{ $i }}.lot_number" @disabled($ro) aria-label="Numéro de lot"
                                       class="w-28 rounded border border-slate-200 px-2 py-1 font-mono text-xs uppercase">
                            </td>
                            <td class="px-3 py-2">
                                <input wire:model.live.debounce.400ms="lines.{{ $i }}.expiry" @disabled($ro) placeholder="MM/AAAA" aria-label="Péremption"
                                       class="w-24 rounded border border-slate-200 px-2 py-1 text-xs">
                                @if ($line['expiry'] !== '')
                                    <span @class(['block text-xs', 'text-slate-500' => $preview, 'text-red-700' => ! $preview])>{{ $preview ? '→ '.$preview : 'illisible' }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-end">
                                <input wire:model.blur="lines.{{ $i }}.quantity_packs" type="number" min="1" @disabled($ro) aria-label="Quantité"
                                       class="w-16 rounded border border-slate-200 px-1 py-1 text-end tabular-nums">
                            </td>
                            <td class="px-3 py-2 text-end">
                                <input wire:model.blur="lines.{{ $i }}.unit_price" type="number" min="0" step="0.01" @disabled($ro) aria-label="Prix d'achat par boîte"
                                       class="w-20 rounded border border-slate-200 px-1 py-1 text-end tabular-nums">
                            </td>
                            <td class="px-3 py-2">
                                <select wire:model.live="lines.{{ $i }}.decision" @disabled($ro) class="rounded border border-slate-200 text-xs" aria-label="Décision">
                                    <option value="accepted">Accepté</option>
                                    <option value="quarantine">Quarantaine</option>
                                    <option value="refused">Refusé</option>
                                </select>
                                @if ($line['decision'] !== 'accepted')
                                    <input wire:model.blur="lines.{{ $i }}.anomaly" @disabled($ro) placeholder="Anomalie constatée"
                                           class="mt-1 block w-40 rounded border border-slate-200 px-2 py-1 text-xs">
                                @endif
                            </td>
                            <td class="px-2 py-2 text-end whitespace-nowrap">
                                @unless ($ro)
                                    <button wire:click="splitLine({{ $i }})" class="rounded px-1 text-slate-500 hover:bg-slate-100" title="Ajouter un autre lot de ce produit">⧉</button>
                                    <button wire:click="removeLine({{ $i }})" class="rounded px-1 text-slate-400 hover:bg-red-50 hover:text-red-700" aria-label="Retirer la ligne">✕</button>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-slate-500">Scannez les produits du bon de livraison.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <p class="text-sm">Total accepté : <span class="font-semibold tabular-nums">{{ number_format($total, 2, ',', ' ') }} MRU</span> · {{ count($lines) }} ligne(s)</p>
            @unless ($ro)
                <button wire:click="cancel" wire:confirm="Annuler cette réception ?" class="ms-auto rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-white">Annuler</button>
                <button wire:click="save" class="rounded-lg border border-emerald-700 px-3 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-50">Enregistrer le brouillon</button>
                <button wire:click="validateReceipt" wire:confirm="Valider la réception ? Les lots seront créés et le stock mis à jour."
                        @disabled(! $lines) class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-40">
                    Valider la réception
                </button>
            @endunless
        </div>
    </main>
</div>
