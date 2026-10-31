@php
    $statuses = ['draft' => 'Brouillon', 'sent' => 'Envoyée', 'partially_received' => 'Reçue en partie', 'received' => 'Reçue', 'cancelled' => 'Annulée'];
    $receiptLabels = ['draft' => 'Brouillon', 'validated' => 'Validée', 'cancelled' => 'Annulée'];
    $order = $this->order;
    $editable = $this->editable;
    $poLines = $order?->lines->keyBy('presentation_id');
    $total = collect($lines)->sum(fn ($l) => (int) $l['quantity_packs'] * (float) $l['unit_price']);
    $input = 'rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100';
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('orders.index') }}" class="text-sm text-emerald-800 hover:underline">← Commandes</a>
            <h1 class="text-xl font-semibold">Commande {{ $order?->number ?? '(nouvelle)' }}</h1>
            @if ($order)
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">{{ $statuses[$order->status] }}</span>
                @if ($order->ordered_at) <span class="text-sm text-slate-500">envoyée le {{ $order->ordered_at->format('d/m/Y') }}</span> @endif
            @endif
            <div class="ms-auto flex gap-2 print:hidden">
                @if ($order && $order->status !== 'draft')
                    <button onclick="window.print()" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Imprimer</button>
                @endif
                @if ($order && in_array($order->status, ['sent', 'partially_received']))
                    <button wire:click="receive" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Réceptionner</button>
                @endif
                @if ($order?->status === 'partially_received')
                    <button wire:click="closeRemainder" wire:confirm="Solder la commande ? Le reliquat ne sera plus attendu." class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Solder le reliquat</button>
                @endif
                @if ($order && in_array($order->status, ['draft', 'sent']))
                    <button wire:click="cancel" wire:confirm="Annuler cette commande ?" class="rounded-lg px-3 py-2 text-sm text-red-700 hover:bg-red-50">Annuler</button>
                @endif
            </div>
        </div>

        @if ($error) <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $error }}</p> @endif
        @if ($message) <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $message }}</p> @endif

        <div class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
            <label class="block text-sm">
                <span class="font-medium">Fournisseur</span>
                <select wire:model="supplierId" @disabled(! $editable) class="mt-1 block {{ $input }}">
                    @foreach ($this->suppliers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                    @if ($order && ! $this->suppliers->contains('id', $order->supplier_id))
                        <option value="{{ $order->supplier_id }}">{{ $order->supplier->name }}</option>
                    @endif
                </select>
            </label>
            <label class="block text-sm">
                <span class="font-medium">Livraison prévue</span>
                <input type="date" wire:model="expectedAt" @disabled(! $editable) class="mt-1 block {{ $input }}">
            </label>
            <label class="block min-w-64 flex-1 text-sm">
                <span class="font-medium">Notes</span>
                <input wire:model="notes" @disabled(! $editable) class="mt-1 block w-full {{ $input }}">
            </label>
            @if ($this->suppliers->isEmpty())
                <p class="w-full text-sm text-amber-700">Aucun fournisseur actif : <a href="{{ route('suppliers.index') }}" class="underline">créez-en un</a>.</p>
            @endif
        </div>

        @if ($editable)
            <div class="relative flex gap-2 print:hidden">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Scanner ou rechercher un produit"
                       x-init="$nextTick(() => $el.focus())"
                       x-on:keydown.enter.prevent="$wire.addFromSearch($el.value)"
                       class="flex-1 {{ $input }}">
                <button wire:click="suggest" class="rounded-lg border border-emerald-700 px-3 py-2 text-sm text-emerald-800">Ajouter les produits sous le seuil</button>
                @if ($this->results->isNotEmpty())
                    <ul class="absolute top-full z-10 mt-1 w-full divide-y divide-slate-100 rounded-lg bg-white shadow-lg">
                        @foreach ($this->results as $r)
                            <li wire:key="r-{{ $r->id }}">
                                <button wire:click="add('{{ $r->id }}')" class="w-full px-3 py-2 text-start text-sm hover:bg-slate-50">
                                    {{ $r->medicine->brand_name }} {{ $r->medicine->strength }} <span class="font-mono text-xs text-slate-500">{{ $r->sku }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">Produit</th>
                        <th class="px-3 py-2 text-end">Boîtes</th>
                        @if (! $editable) <th class="px-3 py-2 text-end">Reçues</th> @endif
                        <th class="px-3 py-2 text-end">Prix / boîte</th>
                        <th class="px-3 py-2 text-end">Montant</th>
                        @if ($editable) <th class="print:hidden"></th> @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($lines as $i => $line)
                        @php $p = $this->presentations->get($line['presentation_id']); $po = $poLines?->get($line['presentation_id']); @endphp
                        <tr wire:key="line-{{ $line['presentation_id'] }}">
                            <td class="px-3 py-2">{{ $p?->medicine->brand_name }} {{ $p?->medicine->strength }} <span class="font-mono text-xs text-slate-500">{{ $p?->sku }}</span></td>
                            <td class="px-3 py-2 text-end">
                                @if ($editable)
                                    <input type="number" min="1" wire:model.blur="lines.{{ $i }}.quantity_packs" class="w-20 rounded border border-slate-300 px-2 py-1 text-end">
                                @else
                                    {{ $line['quantity_packs'] }}
                                @endif
                            </td>
                            @if (! $editable)
                                <td @class(['px-3 py-2 text-end', 'text-emerald-700' => $po && $po->received_packs >= $po->quantity_packs, 'text-amber-700' => $po && $po->received_packs > 0 && $po->received_packs < $po->quantity_packs])>{{ $po?->received_packs ?? 0 }}</td>
                            @endif
                            <td class="px-3 py-2 text-end">
                                @if ($editable)
                                    <input type="number" min="0" step="0.01" wire:model.blur="lines.{{ $i }}.unit_price" class="w-28 rounded border border-slate-300 px-2 py-1 text-end">
                                @else
                                    {{ number_format((float) $line['unit_price'], 2, ',', ' ') }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-end">{{ number_format((int) $line['quantity_packs'] * (float) $line['unit_price'], 2, ',', ' ') }}</td>
                            @if ($editable)
                                <td class="px-3 py-2 text-end print:hidden"><button wire:click="removeLine({{ $i }})" class="text-red-700" title="Retirer">×</button></td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">Scannez ou recherchez des produits à commander.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-slate-50 font-semibold">
                    <tr>
                        <td class="px-3 py-2" colspan="{{ $editable ? 3 : 4 }}">Total</td>
                        <td class="px-3 py-2 text-end">{{ number_format($total, 2, ',', ' ') }} MRU</td>
                        @if ($editable) <td></td> @endif
                    </tr>
                </tfoot>
            </table>
        </div>

        @if ($editable)
            <div class="flex gap-2 print:hidden">
                <button wire:click="save" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">Enregistrer le brouillon</button>
                <button wire:click="send" wire:confirm="Envoyer la commande ? Elle ne sera plus modifiable." class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Envoyer la commande</button>
            </div>
        @endif

        @if ($this->receipts->isNotEmpty())
            <section class="rounded-lg bg-white p-4 text-sm shadow-sm print:hidden">
                <h2 class="mb-2 font-semibold">Réceptions</h2>
                <ul class="space-y-1">
                    @foreach ($this->receipts as $r)
                        <li><a href="{{ route('receipts.edit', $r) }}" class="font-mono text-emerald-800 hover:underline">{{ $r->number }}</a> — {{ $receiptLabels[$r->status] }} — {{ $r->received_at->format('d/m/Y') }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </main>
</div>
