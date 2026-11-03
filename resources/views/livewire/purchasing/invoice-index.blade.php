@php
    $statuses = ['open' => 'À régler', 'partially_paid' => 'Réglée en partie', 'paid' => 'Réglée', 'cancelled' => 'Annulée'];
    $filters = ['unpaid' => 'Non soldées', 'overdue' => 'Échues', 'paid' => 'Réglées', 'all' => 'Toutes'];
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">Factures fournisseurs</h1>
            <button wire:click="openAdvance" class="ms-auto rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm hover:bg-slate-50">Verser un acompte</button>
            <a href="{{ route('invoices.create') }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Saisir une facture</a>
        </div>

        @if ($error) <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $error }}</p> @endif
        @if ($message) <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $message }}</p> @endif

        @if ($advance)
            @php $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm'; @endphp
            <form wire:submit="saveAdvance" class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
                <label class="block text-sm">
                    <span class="font-medium">Fournisseur</span>
                    <select wire:model="advance.supplier_id" class="{{ $input }}">
                        @foreach ($suppliers as $s) <option value="{{ $s->id }}">{{ $s->name }}</option> @endforeach
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Montant</span>
                    <input wire:model="advance.amount" inputmode="decimal" class="{{ $input }} w-36">
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Mode</span>
                    <select wire:model="advance.method" class="{{ $input }}">
                        @foreach ($methods as $k => $v) <option value="{{ $k }}">{{ $v }}</option> @endforeach
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Référence</span>
                    <input wire:model="advance.reference" class="{{ $input }} w-48">
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Date</span>
                    <input type="date" wire:model="advance.paid_on" class="{{ $input }}">
                </label>
                <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Enregistrer l'acompte</button>
                <button type="button" wire:click="$set('advance', null)" class="rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100">Annuler</button>
            </form>
        @endif

        @if ($debts->isNotEmpty())
            <section class="grid gap-3 sm:grid-cols-3">
                @foreach ($debts as $d)
                    <div class="rounded-lg bg-white p-3 text-sm shadow-sm">
                        <div class="flex gap-2 font-medium">
                            {{ $d->name }}
                            <a href="{{ route('suppliers.statement', $d->id) }}" target="_blank" class="ms-auto text-xs font-normal text-emerald-800 hover:underline">Relevé</a>
                        </div>
                        <div class="text-lg font-semibold">{{ $money($d->balance) }} MRU</div>
                        @if ($d->overdue > 0) <div class="text-xs text-red-700">dont {{ $money($d->overdue) }} échus</div> @endif
                        @if ($d->advances > 0) <div class="text-xs text-amber-800">{{ $money($d->advances) }} d'acompte à imputer</div> @endif
                    </div>
                @endforeach
            </section>
        @endif

        <nav class="flex gap-2 text-sm">
            @foreach ($filters as $k => $v)
                <button wire:click="$set('filter', '{{ $k }}')" @class(['rounded-full px-3 py-1', 'bg-emerald-700 text-white' => $filter === $k, 'bg-white text-slate-700 shadow-sm' => $filter !== $k])>{{ $v }}</button>
            @endforeach
        </nav>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">Facture</th>
                        <th class="px-3 py-2 text-start">Fournisseur</th>
                        <th class="px-3 py-2 text-start">Date</th>
                        <th class="px-3 py-2 text-start">Échéance</th>
                        <th class="px-3 py-2 text-end">Montant</th>
                        <th class="px-3 py-2 text-end">Reste dû</th>
                        <th class="px-3 py-2 text-start">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($invoices as $i)
                        @php $overdue = in_array($i->status, ['open', 'partially_paid']) && $i->due_date?->toDateString() < $today; @endphp
                        <tr wire:key="{{ $i->id }}" class="hover:bg-slate-50">
                            <td class="px-3 py-2 font-mono"><a href="{{ route('invoices.show', $i) }}" class="text-emerald-800 hover:underline">{{ $i->supplier_invoice_number }}</a></td>
                            <td class="px-3 py-2">{{ $i->supplier->name }}</td>
                            <td class="px-3 py-2">{{ $i->invoice_date->format('d/m/Y') }}</td>
                            <td @class(['px-3 py-2', 'font-semibold text-red-700' => $overdue])>{{ $i->due_date?->format('d/m/Y') }}</td>
                            <td class="px-3 py-2 text-end">{{ $money($i->total_amount) }}</td>
                            <td class="px-3 py-2 text-end">{{ $money($i->total_amount - $i->paid_amount) }}</td>
                            <td class="px-3 py-2">{{ $statuses[$i->status] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-slate-500">Aucune facture.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $invoices->links() }}
    </main>
</div>
