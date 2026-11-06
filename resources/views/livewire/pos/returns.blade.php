@php $fmt = fn (int $c) => number_format($c / 100, 2, ',', ' '); @endphp

<div class="min-h-screen"
     x-on:return-processed.window="window.open($event.detail.url, 'ticket', 'width=380,height=640')">
    <x-nav :register="$registerCode" />

    <main class="mx-auto max-w-4xl space-y-4 p-4">
        <h1 class="text-xl font-semibold">Retour client — {{ $this->register?->name }}</h1>

        @if (! $canRefund)
            <p class="rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-900">Vous n'avez pas le droit d'effectuer des retours. Faites appel à un pharmacien.</p>
        @elseif (! $this->session)
            <p class="rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-900">Aucune session ouverte sur cette caisse : ouvrez-la depuis l'écran Caisse.</p>
        @endif

        @if ($this->sale)
            <button type="button" wire:click="backToList" class="text-sm text-emerald-800 hover:underline">← Tous les reçus</button>
        @else
            <form wire:submit="findSale" class="flex flex-wrap gap-2">
                <label for="ticket" class="sr-only">Rechercher un reçu</label>
                <input id="ticket" wire:model.live.debounce.300ms="ticket" placeholder="N° de reçu, produit, DCI ou caissier" autofocus autocomplete="off"
                       class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-4 py-2">
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-white hover:bg-slate-900">Ouvrir</button>
                <label class="flex w-full items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model.live="allRegisters" class="rounded border-slate-300"> Toutes les caisses
                </label>
            </form>
        @endif

        @if ($error)
            <p class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">{{ $error }}</p>
        @endif

        @if ($this->lastReturn)
            <p class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800" role="status">
                Avoir {{ $this->lastReturn->credit_note_number }} enregistré — remboursé {{ number_format($this->lastReturn->total, 2, ',', ' ') }} MRU ·
                <a href="{{ route('returns.show', $this->lastReturn) }}" target="ticket" class="underline">imprimer l'avoir</a>
            </p>
        @endif

        @if ($receipts)
            <section class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2 text-start">Reçu</th>
                            <th class="px-3 py-2 text-start">Date</th>
                            <th class="px-3 py-2 text-start">Articles</th>
                            <th class="px-3 py-2 text-start">Caissier</th>
                            <th class="px-3 py-2 text-end">Total</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($receipts as $s)
                            @php $names = $s->items->map(fn ($i) => trim($i->presentation->medicine->brand_name.' '.$i->presentation->medicine->strength)); @endphp
                            <tr wire:key="r-{{ $s->id }}" class="hover:bg-slate-50">
                                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $s->ticket_number }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-slate-600">{{ $s->finalized_at->format('d/m/Y H:i') }}</td>
                                <td class="max-w-xs px-3 py-2">
                                    <span class="block truncate" title="{{ $names->join(', ') }}">{{ $names->take(2)->join(', ') }}@if ($names->count() > 2) +{{ $names->count() - 2 }}@endif</span>
                                    @if ($s->returns_count) <span class="text-xs text-amber-800">{{ $s->returns_count }} retour(s) déjà fait(s)</span> @endif
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-slate-600">{{ $s->user?->name }}</td>
                                <td class="px-3 py-2 text-end font-medium tabular-nums whitespace-nowrap">{{ number_format($s->total, 2, ',', ' ') }}</td>
                                <td class="px-3 py-2 text-end whitespace-nowrap">
                                    <a href="{{ route('tickets.show', $s) }}" target="ticket" class="me-3 text-slate-600 hover:underline">Voir</a>
                                    <button wire:click="open('{{ $s->id }}')" class="rounded-lg border border-emerald-700 px-3 py-1 text-emerald-800 hover:bg-emerald-50">Retourner</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">Aucun reçu{{ $ticket !== '' ? ' pour « '.$ticket.' »' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
            {{ $receipts->links() }}
        @endif

        @if ($this->sale)
            @php $total = array_sum(array_column($this->lines, 'amount')); @endphp
            <form wire:submit="submit" class="space-y-4">
                <section class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-4 py-2 text-sm text-slate-600">
                        Reçu <span class="font-mono font-medium">{{ $this->sale->ticket_number }}</span>
                        du {{ $this->sale->finalized_at->format('d/m/Y H:i') }} · {{ number_format($this->sale->total, 2, ',', ' ') }} MRU
                        @if ($this->sale->returns->isNotEmpty())
                            · <span class="text-amber-800">{{ $this->sale->returns->count() }} retour(s) déjà enregistré(s)</span>
                        @endif
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-3 py-2 text-start">Produit</th>
                                <th class="px-3 py-2 text-start">Lot</th>
                                <th class="px-3 py-2 text-end">Vendu</th>
                                <th class="px-3 py-2 text-end">Retourné</th>
                                <th class="px-3 py-2 text-start">Destination</th>
                                <th class="px-3 py-2 text-end">Montant</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($this->lines as $line)
                                <tr wire:key="ret-{{ $line['id'] }}">
                                    <td class="px-3 py-2 font-medium">{{ $line['label'] }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $line['lot'] }} <span class="text-slate-500">· {{ $line['expiry'] }}</span></td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ $line['sold'] }} {{ $line['unit'] }}</td>
                                    <td class="px-3 py-2 text-end">
                                        @if ($line['remaining'] > 0)
                                            <input wire:model.live.debounce.300ms="quantities.{{ $line['id'] }}" type="number" min="0" max="{{ $line['remaining'] }}"
                                                   class="w-16 rounded border border-slate-200 px-1 py-1 text-center tabular-nums" aria-label="Quantité retournée {{ $line['label'] }}">
                                            <span class="block text-xs text-slate-500">max {{ $line['remaining'] }}</span>
                                        @else
                                            <span class="text-xs text-slate-500">déjà retourné</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <select wire:model="dispositions.{{ $line['id'] }}" @disabled(! $canDispose)
                                                class="rounded border border-slate-200 text-xs" aria-label="Destination {{ $line['label'] }}">
                                            <option value="quarantine">Quarantaine</option>
                                            @if ($canDispose)
                                                <option value="restock_approved">Remise en rayon (contrôlé)</option>
                                                <option value="destroy">Destruction</option>
                                            @endif
                                        </select>
                                    </td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ $fmt($line['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>

                <section class="grid gap-3 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-2">
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium">Motif du retour</span>
                        <input wire:model="reason" required placeholder="Erreur de délivrance, produit non ouvert, intolérance…"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Rembourser en</span>
                        <select wire:model.live="paymentMethodId" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($this->paymentMethods as $m)
                                <option value="{{ $m->id }}">{{ $m->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($this->paymentMethods->firstWhere('id', $paymentMethodId)?->reference_allowed)
                        <label class="block">
                            <span class="text-sm font-medium">Référence (facultative)</span>
                            <input wire:model="reference" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </label>
                    @endif
                    <div class="flex items-center justify-between gap-3 sm:col-span-2">
                        <p class="text-lg">Total remboursé : <span class="font-bold tabular-nums">{{ $fmt($total) }} MRU</span></p>
                        <button wire:confirm="Enregistrer le retour et rembourser le client ?" @disabled(! $total || ! $this->session || ! $canRefund)
                                class="rounded-lg bg-emerald-700 px-5 py-2 font-semibold text-white hover:bg-emerald-800 disabled:opacity-40">
                            Valider le retour
                        </button>
                    </div>
                    <p class="text-xs text-slate-500 sm:col-span-2">Par défaut, les produits retournés partent en quarantaine et ne redeviennent pas vendables.</p>
                </section>
            </form>
        @endif
    </main>

    <x-reauth-modal :pending="$reauth" />
</div>
