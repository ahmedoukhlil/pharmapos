@php
    $fmt = fn (int $cents) => number_format($cents / 100, 2, ',', ' ');
    $t = $this->totals;
@endphp

<div class="flex h-screen flex-col"
     x-data
     x-on:keydown.window.f2.prevent="$refs.search?.focus()"
     x-on:keydown.window.f9.prevent="$wire.checkout()"
     x-on:keydown.window.escape="$wire.set('search', '')"
     x-on:sale-finalized.window="window.open($event.detail.url, 'ticket', 'width=380,height=640'); $nextTick(() => $refs.search?.focus())">

    {{-- En-tête --}}
    <x-nav :register="$registerCode">
        <label class="flex items-center gap-2 text-sm">
            <span class="sr-only">Caisse</span>
            <select wire:model.live="registerCode" class="rounded bg-emerald-900 px-2 py-1 text-sm">
                @foreach ($this->registers as $r)
                    <option value="{{ $r->code }}">{{ $r->name }}</option>
                @endforeach
            </select>
        </label>
        @if ($this->session)
            <span class="text-xs text-emerald-200">Session ouverte {{ $this->session->opened_at->format('d/m H:i') }}</span>
        @endif
    </x-nav>

    @if (! $this->session)
        {{-- Ouverture de session (POS-10) --}}
        <main class="flex flex-1 items-center justify-center p-4">
            <form wire:submit="openSession" class="w-full max-w-sm space-y-4 rounded-xl bg-white p-6 shadow">
                <h1 class="text-lg font-semibold">Ouvrir {{ $this->register?->name }}</h1>
                @if ($this->can['open'])
                    <label class="block">
                        <span class="text-sm font-medium">Fonds de caisse initial (MRU)</span>
                        <input wire:model="openingFloat" type="number" min="0" step="0.01" autofocus
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-end tabular-nums">
                    </label>
                    @error('openingFloat') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Ouvrir la session</button>
                @else
                    <p class="text-sm text-slate-600">Vous n'avez pas le droit d'ouvrir une session de caisse.</p>
                @endif
            </form>
        </main>
    @else
        <main class="grid flex-1 grid-cols-1 gap-3 overflow-hidden p-3 lg:grid-cols-[minmax(0,1fr)_22rem]">
            {{-- Colonne gauche : recherche + panier --}}
            <section class="flex min-h-0 flex-col gap-3">
                <div class="relative">
                    <label for="search" class="sr-only">Rechercher ou scanner</label>
                    <input id="search" x-ref="search" wire:model.live.debounce.200ms="search" x-on:keydown.enter.prevent="$wire.addFromSearch($el.value); $el.value = ''"
                           x-init="$nextTick(() => $el.focus())" autocomplete="off" placeholder="Scanner ou rechercher (DCI, nom, code, SKU) — F2"
                           class="w-full rounded-lg border border-slate-300 bg-white px-4 py-3 text-lg shadow-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none">

                    @if ($this->results->isNotEmpty())
                        <ul class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                            @foreach ($this->results as $p)
                                <li wire:key="r-{{ $p->id }}">
                                    <button wire:click="add('{{ $p->id }}')" class="flex w-full items-center gap-3 px-4 py-2 text-start hover:bg-emerald-50 focus:bg-emerald-50 focus:outline-none">
                                        <span class="flex-1">
                                            <span class="font-medium">{{ $p->medicine->brand_name }} {{ $p->medicine->strength }}</span>
                                            <span class="block text-xs text-slate-500">{{ $p->medicine->inn }} · {{ $p->label }} · {{ $p->sku }}</span>
                                        </span>
                                        <span class="tabular-nums">{{ number_format($p->sale_price, 2, ',', ' ') }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @if ($this->heldSales->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-2" aria-label="Ventes en attente">
                        <span class="text-xs font-medium uppercase text-slate-500">En attente</span>
                        @foreach ($this->heldSales as $held)
                            <span wire:key="held-{{ $held->id }}" class="inline-flex items-center overflow-hidden rounded-full border border-amber-300 bg-amber-50 text-sm">
                                <button wire:click="resume('{{ $held->id }}')" class="px-3 py-1 hover:bg-amber-100" title="Reprendre">
                                    {{ $held->held_at->format('H:i') }} · {{ $held->items_count }} art. · {{ number_format($held->total, 2, ',', ' ') }}
                                </button>
                                <button wire:click="cancelHeld('{{ $held->id }}')" wire:confirm="Annuler cette vente en attente et libérer le stock ?"
                                        class="border-s border-amber-300 px-2 py-1 text-amber-800 hover:bg-red-50 hover:text-red-700" aria-label="Annuler la vente en attente">✕</button>
                            </span>
                        @endforeach
                    </div>
                @endif

                @if ($heldSaleId)
                    <p class="rounded-lg bg-sky-50 px-4 py-2 text-sm text-sky-800">Vente en attente reprise : son stock reste réservé jusqu'à l'encaissement.</p>
                @endif

                @if ($error)
                    <p class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">{{ $error }}</p>
                @endif

                <div class="min-h-0 flex-1 overflow-auto rounded-lg bg-white shadow-sm">
                    @if (! $this->lines)
                        <div class="flex h-full flex-col items-center justify-center gap-2 p-8 text-slate-500">
                            <p>Panier vide. Scannez un produit pour commencer.</p>
                            @if ($this->lastSale)
                                <p class="text-sm text-emerald-700">
                                    Vente {{ $this->lastSale->ticket_number }} enregistrée —
                                    rendu {{ number_format($this->lastSale->change_given, 2, ',', ' ') }} MRU ·
                                    <a href="{{ route('tickets.show', $this->lastSale) }}" target="ticket" class="underline">ticket</a>
                                </p>
                            @endif
                        </div>
                    @else
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="px-3 py-2 text-start">Produit</th>
                                    <th class="px-3 py-2 text-start">Lot</th>
                                    <th class="px-3 py-2">Qté</th>
                                    <th class="px-3 py-2 text-end">P.U.</th>
                                    @if ($this->can['discount']) <th class="px-3 py-2 text-end">Remise</th> @endif
                                    <th class="px-3 py-2 text-end">Total</th>
                                    <th class="w-8"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($this->lines as $line)
                                    @php $i = $line['index']; @endphp
                                    <tr wire:key="line-{{ $i }}-{{ $cart[$i]['presentation_id'] }}" @class(['bg-amber-50' => $line['short']])>
                                        <td class="px-3 py-2">
                                            <span class="font-medium">{{ $line['label'] }}</span>
                                            @if ($line['rx'])
                                                <span class="ms-1 rounded bg-sky-100 px-1.5 text-xs text-sky-800" title="Médicament soumis à prescription">Rx</span>
                                            @endif
                                            <span class="block text-xs text-slate-500">{{ $line['presentation'] }}</span>
                                            @if ($line['can_split'])
                                                <select wire:model.live="cart.{{ $i }}.sale_unit" class="mt-1 rounded border border-slate-200 text-xs">
                                                    <option value="pack">Boîte</option>
                                                    <option value="unit">À l'unité</option>
                                                </select>
                                            @endif
                                            @if ($line['short'])
                                                <span class="block text-xs font-medium text-amber-800">Stock vendable insuffisant ({{ $line['available'] }} unités)</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-xs">
                                            @if ($this->can['override'] && $line['batches']->count() > 1)
                                                <select wire:model.live="cart.{{ $i }}.batch_id" class="rounded border border-slate-200 text-xs">
                                                    <option value="">FEFO : {{ $line['fefo']?->lot_number }}</option>
                                                    @foreach ($line['batches'] as $b)
                                                        <option value="{{ $b->id }}">{{ $b->lot_number }} · {{ $b->expiry_date->format('m/Y') }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif ($line['fefo'])
                                                <span class="font-mono">{{ $line['fefo']->lot_number }}</span>
                                                <span class="block text-slate-500">exp. {{ $line['fefo']->expiry_date->format('m/Y') }}</span>
                                            @else
                                                <span class="text-red-700">Aucun lot vendable</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="flex items-center justify-center gap-1">
                                                <button wire:click="increment({{ $i }}, -1)" class="h-8 w-8 rounded border border-slate-200 hover:bg-slate-100" aria-label="Diminuer">−</button>
                                                <input wire:model.live.debounce.300ms="cart.{{ $i }}.quantity" type="number" min="1"
                                                       class="w-14 rounded border border-slate-200 px-1 py-1 text-center tabular-nums" aria-label="Quantité">
                                                <button wire:click="increment({{ $i }}, 1)" class="h-8 w-8 rounded border border-slate-200 hover:bg-slate-100" aria-label="Augmenter">+</button>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($line['unit_price'], 2, ',', ' ') }}</td>
                                        @if ($this->can['discount'])
                                            <td class="px-3 py-2 text-end">
                                                <input wire:model.live.debounce.400ms="cart.{{ $i }}.discount_amount" type="number" min="0" step="0.01"
                                                       class="w-20 rounded border border-slate-200 px-1 py-1 text-end tabular-nums" aria-label="Remise">
                                            </td>
                                        @endif
                                        <td class="px-3 py-2 text-end font-medium tabular-nums">{{ $fmt($line['total']) }}</td>
                                        <td class="px-2">
                                            <button wire:click="remove({{ $i }})" class="rounded px-2 py-1 text-slate-400 hover:bg-red-50 hover:text-red-700" aria-label="Retirer la ligne">✕</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </section>

            {{-- Colonne droite : totaux et paiements --}}
            <aside class="flex min-h-0 flex-col gap-3 rounded-lg bg-white p-4 shadow-sm">
                <div class="text-center">
                    <p class="text-sm text-slate-500">Total à payer</p>
                    <p class="text-4xl font-bold tabular-nums text-emerald-800">{{ $fmt($t['total']) }} <span class="text-lg font-medium">MRU</span></p>
                    @if ($t['total'] !== $t['raw'])
                        <p class="text-xs text-slate-500">arrondi de {{ $fmt($t['raw']) }}</p>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-2">
                    @foreach ($this->paymentMethods as $m)
                        <button wire:click="addPayment('{{ $m->id }}')" @disabled(! $this->lines)
                                class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium hover:border-emerald-600 hover:bg-emerald-50 disabled:opacity-40">
                            {{ $m->name }}
                        </button>
                    @endforeach
                </div>

                <div class="min-h-0 flex-1 space-y-2 overflow-auto">
                    @foreach ($payments as $j => $p)
                        @php $method = $this->paymentMethods->firstWhere('id', $p['payment_method_id']); @endphp
                        <div wire:key="pay-{{ $j }}" class="rounded-lg border border-slate-200 p-2">
                            <div class="flex items-center gap-2">
                                <span class="flex-1 text-sm font-medium">{{ $method?->name }}</span>
                                <input wire:model.live.debounce.300ms="payments.{{ $j }}.amount" type="number" min="0" step="0.01"
                                       class="w-28 rounded border border-slate-200 px-2 py-1 text-end tabular-nums" aria-label="Montant {{ $method?->name }}">
                                <button wire:click="removePayment({{ $j }})" class="px-1 text-slate-400 hover:text-red-700" aria-label="Retirer le paiement">✕</button>
                            </div>
                            @if ($method?->reference_allowed)
                                <input wire:model="payments.{{ $j }}.reference" placeholder="Référence (facultative, déclarative)"
                                       class="mt-2 w-full rounded border border-slate-200 px-2 py-1 text-xs">
                            @endif
                        </div>
                    @endforeach
                </div>

                <dl class="space-y-1 border-t border-slate-100 pt-3 text-sm">
                    <div class="flex justify-between"><dt>Payé</dt><dd class="tabular-nums">{{ $fmt($t['paid']) }}</dd></div>
                    <div class="flex justify-between"><dt>Reste dû</dt><dd @class(['tabular-nums', 'font-semibold text-red-700' => $t['due'] > 0])>{{ $fmt($t['due']) }}</dd></div>
                    <div class="flex justify-between text-base font-semibold"><dt>Rendu</dt><dd class="tabular-nums">{{ $fmt($t['change']) }}</dd></div>
                </dl>

                <div class="flex gap-2">
                    <button wire:click="clear" wire:confirm="Vider le panier ?" @disabled(! $this->lines)
                            class="rounded-lg border border-slate-300 px-3 py-3 text-sm hover:bg-slate-50 disabled:opacity-40">Vider</button>
                    <button wire:click="hold" @disabled(! $this->lines)
                            class="rounded-lg border border-amber-400 px-3 py-3 text-sm text-amber-900 hover:bg-amber-50 disabled:opacity-40">Attente</button>
                    <button wire:click="checkout" wire:loading.attr="disabled" @disabled(! $this->lines || ! $this->can['sell'])
                            class="flex-1 rounded-lg bg-emerald-700 px-4 py-3 text-lg font-semibold text-white hover:bg-emerald-800 disabled:opacity-40">
                        <span wire:loading.remove wire:target="checkout">Encaisser — F9</span>
                        <span wire:loading wire:target="checkout">Validation…</span>
                    </button>
                </div>
            </aside>
        </main>
    @endif

    <x-reauth-modal :pending="$reauth" />
</div>
