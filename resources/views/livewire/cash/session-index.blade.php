@php
    $labels = ['open' => 'Ouverte', 'closed' => 'À valider', 'validated' => 'Validée'];
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-5xl space-y-4 p-4">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold">Sessions de caisse</h1>
            <select wire:model.live="status" class="ms-auto rounded-lg border border-slate-300 px-2 py-1 text-sm" aria-label="Filtrer par statut">
                <option value="closed">À valider</option>
                <option value="open">Ouvertes</option>
                <option value="validated">Validées</option>
                <option value="all">Toutes</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">Caisse</th>
                        <th class="px-3 py-2 text-start">Ouverture</th>
                        <th class="px-3 py-2 text-start">Clôture</th>
                        <th class="px-3 py-2 text-end">Théorique</th>
                        <th class="px-3 py-2 text-end">Compté</th>
                        <th class="px-3 py-2 text-end">Écart</th>
                        <th class="px-3 py-2 text-start">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($sessions as $s)
                        <tr wire:key="{{ $s->id }}" class="hover:bg-slate-50">
                            <td class="px-3 py-2"><a href="{{ route('sessions.show', $s) }}" class="font-medium text-emerald-800 underline-offset-2 hover:underline">{{ $s->register->name }}</a></td>
                            <td class="px-3 py-2">{{ $s->opened_at->format('d/m H:i') }} · {{ $s->opener->name }}</td>
                            <td class="px-3 py-2">{{ $s->closed_at?->format('d/m H:i') ?? '—' }} {{ $s->closer ? '· '.$s->closer->name : '' }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $money($s->expected_cash) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $money($s->counted_cash) }}</td>
                            <td @class(['px-3 py-2 text-end tabular-nums font-medium', 'text-red-700' => (float) $s->difference < 0, 'text-amber-700' => (float) $s->difference > 0])>{{ $money($s->difference) }}</td>
                            <td class="px-3 py-2">{{ $labels[$s->status] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-slate-500">Aucune session.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $sessions->links() }}
    </main>
</div>
