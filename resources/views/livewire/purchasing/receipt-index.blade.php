@php $labels = ['draft' => 'Brouillon', 'validated' => 'Validée', 'cancelled' => 'Annulée']; @endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-5xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">Réceptions fournisseurs</h1>
            <select wire:model.live="status" class="ms-auto rounded-lg border border-slate-300 px-2 py-1 text-sm" aria-label="Filtrer par statut">
                <option value="all">Toutes</option>
                <option value="draft">Brouillons</option>
                <option value="validated">Validées</option>
                <option value="cancelled">Annulées</option>
            </select>
            @if ($canManage)
                <a href="{{ route('receipts.create') }}" class="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Nouvelle réception</a>
            @endif
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">N°</th>
                        <th class="px-3 py-2 text-start">Fournisseur</th>
                        <th class="px-3 py-2 text-start">BL</th>
                        <th class="px-3 py-2 text-start">Date</th>
                        <th class="px-3 py-2 text-end">Lignes</th>
                        <th class="px-3 py-2 text-start">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($receipts as $r)
                        <tr wire:key="{{ $r->id }}" class="hover:bg-slate-50">
                            <td class="px-3 py-2 font-mono"><a href="{{ route('receipts.edit', $r) }}" class="text-emerald-800 hover:underline">{{ $r->number }}</a></td>
                            <td class="px-3 py-2">{{ $r->supplier->name }}</td>
                            <td class="px-3 py-2">{{ $r->delivery_note_ref ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $r->received_at->format('d/m/Y H:i') }} · {{ $r->receiver->name }}</td>
                            <td class="px-3 py-2 text-end">{{ $r->lines_count }}</td>
                            <td class="px-3 py-2">{{ $labels[$r->status] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">Aucune réception.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $receipts->links() }}
    </main>
</div>
