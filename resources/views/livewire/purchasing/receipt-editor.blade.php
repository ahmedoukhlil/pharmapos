@php
    $labels = ['draft' => 'Brouillon', 'validated' => 'Validée', 'cancelled' => 'Annulée'];
    $total = collect($lines)->sum(fn ($l) => (int) $l['quantity_packs'] * (float) $l['unit_price'] * ($l['decision'] === 'refused' ? 0 : 1));
    $ro = ! $this->editable;
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
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
                @if ($this->receipt->status === 'draft')
                    <livewire:scanner.scanner-panel mode="receipt" :context-id="$this->receipt->id" :key="'scanner-'.$this->receipt->id" />
                @endif
                @if ($this->receipt->purchaseOrder)
                    <a href="{{ route('orders.edit', $this->receipt->purchaseOrder) }}" class="text-sm text-emerald-800 hover:underline">Commande {{ $this->receipt->purchaseOrder->number }}</a>
                @endif
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

        @if (! $ro && ! $note)
            {{-- Lecture automatique du bordereau papier --}}
            <section class="flex flex-wrap items-center gap-3 rounded-lg border border-dashed border-emerald-300 bg-emerald-50/50 p-4 text-sm"
                     x-data="{ uploading: false }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false">
                <div class="min-w-48 flex-1">
                    <p class="font-semibold text-emerald-900">Lire un bordereau de livraison</p>
                    <p class="text-slate-600">Photos ou PDF, plusieurs pages possibles. Les lignes sont proposées pour contrôle avant d'être ajoutées.</p>
                </div>
                <label class="cursor-pointer rounded-lg border border-emerald-700 bg-white px-3 py-2 font-medium text-emerald-800 hover:bg-emerald-50">
                    {{ $noteFiles ? count($noteFiles).' page(s) choisie(s)' : 'Photographier / choisir' }}
                    <input type="file" wire:model="noteFiles" multiple accept="image/*,application/pdf" class="sr-only">
                </label>
                <button wire:click="readNote" wire:loading.attr="disabled" wire:target="readNote,noteFiles" x-bind:disabled="uploading" @disabled(! $noteFiles)
                        class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                    <span wire:loading.remove wire:target="readNote">Lire le bordereau</span>
                    <span wire:loading wire:target="readNote">Lecture en cours… (jusqu'à 1 min)</span>
                </button>
                @error('noteFiles') <p class="w-full text-red-700">{{ $message }}</p> @enderror
                @error('noteFiles.*') <p class="w-full text-red-700">{{ $message }}</p> @enderror
            </section>
        @endif

        @if ($note)
            @php
                $statusStyle = ['ok' => ['✓ Reconnu', 'bg-emerald-100 text-emerald-800'], 'check' => ['À vérifier', 'bg-amber-100 text-amber-900'], 'unknown' => ['Inconnu', 'bg-red-100 text-red-800']];
                $readTotal = collect($note['lines'])->sum(fn ($l) => (float) $l['quantity'] * (float) $l['unit_price']);
                $diff = $note['total'] > 0 && abs($note['total'] - $readTotal) > 1;
            @endphp
            <section class="space-y-3 rounded-lg border-2 border-emerald-600 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                    <h2 class="text-lg font-semibold">Bordereau lu — à contrôler</h2>
                    <span class="text-sm text-slate-600">
                        @if ($note['number']) N° {{ $note['number'] }} @endif
                        @if ($note['date']) · {{ $note['date'] }} @endif
                        @if ($note['supplier_name']) · {{ $note['supplier_name'] }} @endif
                    </span>
                </div>
                @if ($note['supplier_name'] && ! str_contains(\Illuminate\Support\Str::lower($note['supplier_name']), \Illuminate\Support\Str::lower(\Illuminate\Support\Str::before($this->suppliers->firstWhere('id', $supplierId)?->name ?? '', ' '))))
                    <p class="rounded bg-amber-50 px-3 py-1.5 text-sm text-amber-900">Le bordereau semble venir de « {{ $note['supplier_name'] }} » : vérifiez le fournisseur choisi.</p>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-2 py-2 text-start">Sur le bordereau</th>
                                <th class="px-2 py-2 text-start">Produit PharmaPOS</th>
                                <th class="px-2 py-2 text-start">Lot</th>
                                <th class="px-2 py-2 text-start">Péremption</th>
                                <th class="px-2 py-2 text-end">Boîtes</th>
                                <th class="px-2 py-2 text-end">Prix achat</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($note['lines'] as $i => $l)
                                <tr wire:key="note-{{ $i }}" @class(['bg-red-50/40' => $l['status'] === 'unknown', 'bg-amber-50/40' => $l['status'] === 'check'])>
                                    <td class="px-2 py-2 align-top">
                                        <span class="font-medium">{{ $l['designation'] }}</span>
                                        @if ($l['code']) <span class="block font-mono text-xs text-slate-500">{{ $l['code'] }}</span> @endif
                                        <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs {{ $statusStyle[$l['status']][1] }}">{{ $statusStyle[$l['status']][0] }}</span>
                                        @foreach ($l['warnings'] as $w) <span class="block text-xs text-amber-800">⚠ {{ $w }}</span> @endforeach
                                    </td>
                                    <td class="px-2 py-2 align-top">
                                        <select wire:model.live="note.lines.{{ $i }}.presentation_id" class="w-64 rounded border border-slate-300 px-2 py-1">
                                            <option value="">— Choisir le produit —</option>
                                            @if ($l['candidates'])
                                                <optgroup label="Suggestions">
                                                    @foreach ($l['candidates'] as $c) <option value="{{ $c['id'] }}">{{ $c['label'] }}</option> @endforeach
                                                </optgroup>
                                            @endif
                                            <optgroup label="Catalogue">
                                                @foreach ($this->catalogue as $p)
                                                    <option value="{{ $p->id }}">{{ $p->medicine->brand_name }} {{ $p->medicine->strength }} — {{ $p->label }}</option>
                                                @endforeach
                                            </optgroup>
                                        </select>
                                        @if (! $l['presentation_id'])
                                            <a href="{{ route('catalogue.create') }}" target="_blank" class="mt-1 block text-xs text-emerald-800 underline">Créer le produit</a>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2 align-top"><input wire:model="note.lines.{{ $i }}.lot" class="w-28 rounded border border-slate-300 px-2 py-1"></td>
                                    <td class="px-2 py-2 align-top"><input wire:model="note.lines.{{ $i }}.expiry" placeholder="MM/AAAA" class="w-24 rounded border border-slate-300 px-2 py-1"></td>
                                    <td class="px-2 py-2 align-top text-end"><input wire:model="note.lines.{{ $i }}.quantity" inputmode="numeric" class="w-16 rounded border border-slate-300 px-2 py-1 text-end"></td>
                                    <td class="px-2 py-2 align-top text-end"><input wire:model="note.lines.{{ $i }}.unit_price" inputmode="decimal" class="w-24 rounded border border-slate-300 px-2 py-1 text-end"></td>
                                    <td class="px-2 py-2 align-top"><button wire:click="removeNoteLine({{ $i }})" class="text-red-700" title="Retirer la ligne">×</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span>Total lu : <strong class="tabular-nums">{{ number_format($readTotal, 2, ',', ' ') }}</strong> MRU</span>
                    @if ($note['total'] > 0)
                        <span @class(['text-red-700 font-semibold' => $diff])>Total imprimé : {{ number_format($note['total'], 2, ',', ' ') }} MRU {{ $diff ? '— écart, vérifiez les lignes' : '✓' }}</span>
                    @endif
                    <button wire:click="discardNote" wire:confirm="Abandonner la lecture de ce bordereau ?" class="ms-auto rounded-lg border border-slate-300 px-3 py-2">Abandonner</button>
                    <button wire:click="applyNote" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Ajouter à la réception</button>
                </div>
            </section>
        @endif

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
