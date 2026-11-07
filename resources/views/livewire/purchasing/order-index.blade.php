@php
    $statuses = ['draft' => 'Brouillon', 'sent' => 'Envoyée', 'partially_received' => 'Reçue en partie', 'received' => 'Reçue', 'cancelled' => 'Annulée'];
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-5xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 data-tour-order="5" data-tour-title="Commandes" data-tour="Préparez les commandes fournisseurs ; elles servent de base aux réceptions." class="text-xl font-semibold">Commandes fournisseurs</h1>
            <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                <option value="">Tous statuts</option>
                @foreach ($statuses as $k => $v)
                    <option value="{{ $k }}">{{ $v }}</option>
                @endforeach
            </select>
            <a href="{{ route('orders.create') }}" class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Nouvelle commande</a>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">N°</th>
                        <th class="px-3 py-2 text-start">Fournisseur</th>
                        <th class="px-3 py-2 text-start">Commandée le</th>
                        <th class="px-3 py-2 text-end">Lignes</th>
                        <th class="px-3 py-2 text-end">Montant (MRU)</th>
                        <th class="px-3 py-2 text-start">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($orders as $o)
                        <tr wire:key="{{ $o->id }}" class="hover:bg-slate-50">
                            <td class="px-3 py-2 font-mono"><a href="{{ route('orders.edit', $o) }}" class="text-emerald-800 hover:underline">{{ $o->number }}</a></td>
                            <td class="px-3 py-2">{{ $o->supplier->name }}</td>
                            <td class="px-3 py-2">{{ $o->ordered_at?->format('d/m/Y') ?? '-' }}</td>
                            <td class="px-3 py-2 text-end">{{ $o->lines_count }}</td>
                            <td class="px-3 py-2 text-end">{{ number_format((float) $o->total_amount, 2, ',', ' ') }}</td>
                            <td class="px-3 py-2">{{ $statuses[$o->status] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">Aucune commande.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $orders->links() }}
    </main>
</div>
