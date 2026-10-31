@php
    $statuses = ['counting' => 'Comptage', 'review' => 'En revue', 'approved' => 'Approuvé', 'cancelled' => 'Annulé', 'draft' => 'Brouillon'];
    $scopes = ['full' => 'Complet', 'partial' => 'Partiel', 'cycle' => 'Tournant'];
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-5xl space-y-4 p-4">
        <h1 class="text-xl font-semibold">Inventaires</h1>

        @if ($canCount)
            <form wire:submit="open" class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
                <label class="block">
                    <span class="text-sm font-medium">Type</span>
                    <select wire:model="scope" class="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="partial">Partiel (un emplacement)</option>
                        <option value="cycle">Tournant</option>
                        <option value="full">Complet (toute l'officine)</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Emplacement</span>
                    <select wire:model="locationId" class="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Tous</option>
                        @foreach ($locations as $l)
                            <option value="{{ $l->id }}">{{ $l->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button wire:confirm="Ouvrir l'inventaire ? Le stock théorique sera figé maintenant."
                        class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Ouvrir un inventaire</button>
                @if ($error)
                    <p class="w-full text-sm text-red-700" role="alert">{{ $error }}</p>
                @endif
            </form>
        @endif

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">N°</th>
                        <th class="px-3 py-2 text-start">Type</th>
                        <th class="px-3 py-2 text-start">Emplacement</th>
                        <th class="px-3 py-2 text-start">Ouvert le</th>
                        <th class="px-3 py-2 text-end">Lignes</th>
                        <th class="px-3 py-2 text-start">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($sessions as $s)
                        <tr wire:key="{{ $s->id }}" class="hover:bg-slate-50">
                            <td class="px-3 py-2 font-mono"><a href="{{ route('inventories.show', $s) }}" class="text-emerald-800 hover:underline">{{ $s->number }}</a></td>
                            <td class="px-3 py-2">{{ $scopes[$s->scope] }}</td>
                            <td class="px-3 py-2">{{ $s->location?->name ?? 'Tous' }}</td>
                            <td class="px-3 py-2">{{ $s->snapshot_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2 text-end">{{ $s->lines_count }}</td>
                            <td class="px-3 py-2">{{ $statuses[$s->status] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">Aucun inventaire.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $sessions->links() }}
    </main>
</div>
