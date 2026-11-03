@php
    $statuses = ['open' => 'À régler', 'partially_paid' => 'Réglée en partie', 'paid' => 'Réglée', 'cancelled' => 'Annulée'];
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm';
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('invoices.index') }}" class="text-sm text-emerald-800 hover:underline">← Factures</a>
            @if ($invoice)
                <h1 class="text-xl font-semibold">Facture {{ $invoice->supplier_invoice_number }} — {{ $invoice->supplier->name }}</h1>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">{{ $statuses[$invoice->status] }}</span>
                @if ($invoice->status === 'open' && (float) $invoice->paid_amount == 0)
                    <button wire:click="cancel" wire:confirm="Annuler cette facture ?" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-700 hover:bg-red-50">Annuler la facture</button>
                @endif
            @else
                <h1 class="text-xl font-semibold">Saisir une facture fournisseur</h1>
            @endif
        </div>

        @if ($error) <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $error }}</p> @endif
        @if ($message) <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $message }}</p> @endif

        @if (! $invoice)
            <form wire:submit="create" class="space-y-4">
                <div class="grid gap-3 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-5">
                    <label class="block text-sm sm:col-span-2">
                        <span class="font-medium">Fournisseur</span>
                        <select wire:model.live="form.supplier_id" class="{{ $input }}">
                            @foreach ($suppliers as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium">N° de facture *</span>
                        <input wire:model="form.supplier_invoice_number" class="{{ $input }}">
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium">Date *</span>
                        <input type="date" wire:model="form.invoice_date" class="{{ $input }}">
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium">Échéance</span>
                        <input type="date" wire:model="form.due_date" class="{{ $input }}">
                        <span class="text-xs text-slate-500">Vide = délai du fournisseur</span>
                    </label>
                </div>

                <section class="rounded-lg bg-white p-4 shadow-sm">
                    <h2 class="mb-2 font-semibold">Réceptions facturées</h2>
                    @forelse ($receipts as [$r, $value])
                        <label wire:key="r-{{ $r->id }}" class="flex items-center gap-3 border-b border-slate-100 py-2 text-sm last:border-0">
                            <input type="checkbox" value="{{ $r->id }}" wire:model.live="receiptIds">
                            <span class="font-mono">{{ $r->number }}</span>
                            <span>{{ $r->validated_at?->format('d/m/Y') }}</span>
                            @if ($r->delivery_note_ref) <span class="text-slate-500">BL {{ $r->delivery_note_ref }}</span> @endif
                            @if ($r->purchaseOrder) <span class="text-slate-500">cde {{ $r->purchaseOrder->number }}</span> @endif
                            <span class="ms-auto">{{ $money($value) }} MRU</span>
                        </label>
                    @empty
                        <p class="text-sm text-slate-500">Aucune réception validée non facturée pour ce fournisseur.</p>
                    @endforelse
                </section>

                <div class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
                    <label class="block text-sm">
                        <span class="font-medium">Montant de la facture (MRU) *</span>
                        <input wire:model.live.debounce.500ms="form.total_amount" inputmode="decimal" class="{{ $input }} w-48">
                    </label>
                    <div class="text-sm">
                        Réceptions cochées : <strong>{{ $money($selectedValue) }}</strong> MRU
                        @if ($receiptIds) <button type="button" wire:click="useReceiptsTotal" class="ms-2 text-emerald-800 underline">reprendre ce montant</button> @endif
                        @php $diff = (float) str_replace([' ', ','], ['', '.'], $form['total_amount'] ?: '0') - $selectedValue; @endphp
                        @if ($receiptIds && $form['total_amount'] !== '' && abs($diff) >= 0.01)
                            <div class="text-amber-700">Écart avec les réceptions : {{ $diff > 0 ? '+' : '' }}{{ $money($diff) }} MRU</div>
                        @endif
                    </div>
                    <button class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Enregistrer la facture</button>
                </div>
            </form>
        @else
            <div class="grid gap-3 sm:grid-cols-4">
                @foreach ([['Date', $invoice->invoice_date->format('d/m/Y')], ['Échéance', $invoice->due_date?->format('d/m/Y')], ['Montant', $money($invoice->total_amount).' MRU'], ['Reste dû', $money($invoice->total_amount - $invoice->paid_amount).' MRU']] as [$label, $value])
                    <div class="rounded-lg bg-white p-3 text-sm shadow-sm"><div class="text-slate-500">{{ $label }}</div><div class="text-lg font-semibold">{{ $value }}</div></div>
                @endforeach
            </div>

            <section class="rounded-lg bg-white shadow-sm">
                <div @class(['flex flex-wrap items-center gap-4 rounded-t-lg px-4 py-2 text-sm', 'bg-emerald-50 text-emerald-800' => $reconciliation['matched'], 'bg-amber-50 text-amber-800' => ! $reconciliation['matched']])>
                    <strong>Rapprochement {{ $reconciliation['matched'] ? 'conforme' : 'à vérifier' }}</strong>
                    <span>Valeur reçue : {{ $money($reconciliation['expected']) }} MRU</span>
                    <span>Écart facture : {{ $reconciliation['difference'] > 0 ? '+' : '' }}{{ $money($reconciliation['difference']) }} MRU</span>
                    @if ($reconciliation['pending_packs']) <span>Reliquat commandé non livré : {{ $reconciliation['pending_packs'] }} boîte(s)</span> @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-3 py-2 text-start">Réception</th>
                                <th class="px-3 py-2 text-start">Produit</th>
                                <th class="px-3 py-2 text-end">Commandé</th>
                                <th class="px-3 py-2 text-end">Reçu</th>
                                <th class="px-3 py-2 text-end">Facturable</th>
                                <th class="px-3 py-2 text-end">Prix</th>
                                <th class="px-3 py-2 text-end">Montant</th>
                                <th class="px-3 py-2 text-start">Anomalies</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($reconciliation['lines'] as $l)
                                <tr @class(['bg-amber-50/50' => $l->anomalies])>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $l->receipt }} @if ($l->order) <div class="text-slate-500">{{ $l->order }}</div> @endif</td>
                                    <td class="px-3 py-2">{{ $l->product }} <span class="font-mono text-xs text-slate-500">{{ $l->sku }}</span></td>
                                    <td class="px-3 py-2 text-end">{{ $l->ordered ?? '—' }}</td>
                                    <td class="px-3 py-2 text-end">{{ $l->received }}</td>
                                    <td class="px-3 py-2 text-end">{{ $l->invoiceable }}</td>
                                    <td class="px-3 py-2 text-end">{{ $money($l->unit_price) }}</td>
                                    <td class="px-3 py-2 text-end">{{ $money($l->amount_cents / 100) }}</td>
                                    <td class="px-3 py-2 text-xs text-amber-800">{{ implode(' · ', $l->anomalies) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-6 text-center text-slate-500">Facture sans réception rattachée : rapprochement impossible.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg bg-white p-4 shadow-sm">
                <h2 class="mb-2 font-semibold">Règlements</h2>
                @if ($payments->isNotEmpty())
                    <ul class="mb-3 divide-y divide-slate-100 text-sm">
                        @foreach ($payments as $p)
                            <li class="flex gap-3 py-1.5">
                                <span>{{ $p->paid_on->format('d/m/Y') }}</span>
                                <span>{{ $methods[$p->method] ?? $p->method }}</span>
                                <span class="font-mono text-slate-500">{{ $p->reference }}</span>
                                <span class="ms-auto font-semibold">{{ $money($p->amount) }} MRU</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($advances->isNotEmpty())
                    <div class="mb-3 rounded-lg bg-amber-50 p-3 text-sm">
                        <div class="mb-1 font-medium text-amber-900">Acomptes disponibles chez ce fournisseur</div>
                        @foreach ($advances as $a)
                            <div class="flex items-center gap-3 py-1" wire:key="adv-{{ $a->id }}">
                                <span>{{ $a->paid_on->format('d/m/Y') }}</span>
                                <span>{{ $methods[$a->method] ?? $a->method }} <span class="font-mono text-slate-500">{{ $a->reference }}</span></span>
                                <span class="ms-auto font-semibold">{{ $money($a->amount) }} MRU</span>
                                <button type="button" wire:click="applyAdvance('{{ $a->id }}')" class="rounded-lg border border-amber-400 px-3 py-1 text-amber-900 hover:bg-amber-100">Imputer</button>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (in_array($invoice->status, ['open', 'partially_paid']))
                    <form wire:submit="pay" class="flex flex-wrap items-end gap-3">
                        <label class="block text-sm">
                            <span class="font-medium">Montant</span>
                            <input wire:model="payment.amount" inputmode="decimal" class="{{ $input }} w-36">
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium">Mode</span>
                            <select wire:model="payment.method" class="{{ $input }}">
                                @foreach ($methods as $k => $v) <option value="{{ $k }}">{{ $v }}</option> @endforeach
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium">Référence</span>
                            <input wire:model="payment.reference" class="{{ $input }} w-48">
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium">Date</span>
                            <input type="date" wire:model="payment.paid_on" class="{{ $input }}">
                        </label>
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Enregistrer le règlement</button>
                    </form>
                    <p class="mt-2 text-xs text-slate-500">Règlement déclaratif : un paiement en espèces n'est pas prélevé sur un tiroir-caisse.</p>
                @endif
            </section>
        @endif
    </main>
</div>
