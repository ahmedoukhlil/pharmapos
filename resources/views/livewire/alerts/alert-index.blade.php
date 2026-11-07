@php
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $tabs = ['expiry' => ['Péremptions', $expiring->flatten(1)->count()], 'low_stock' => ['Stock bas', $lowStock->count()]];
    if ($canCash) {
        $tabs['cash'] = ['Écarts de caisse', $variances->count()];
    }
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <h1 class="text-xl font-semibold">Alertes</h1>

        @if ($backupIssue)
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
                Sauvegarde : {{ $backupIssue }}
                @if (\App\Livewire\System\BackupIndex::allowed()) <a href="{{ route('backups.index') }}" class="ms-1 font-semibold underline">Voir les sauvegardes</a> @endif
            </p>
        @endif

        <nav class="flex gap-2 border-b border-slate-200 text-sm">
            @foreach ($tabs as $key => [$label, $n])
                <button wire:click="$set('tab', '{{ $key }}')" @class(['-mb-px border-b-2 px-3 py-2', 'border-emerald-700 font-semibold text-emerald-800' => $tab === $key, 'border-transparent text-slate-600' => $tab !== $key])>
                    {{ $label }} <span class="ms-1 rounded-full bg-slate-100 px-2 text-xs">{{ $n }}</span>
                </button>
            @endforeach
        </nav>

        @if ($tab === 'expiry')
            @forelse ($expiring->sortKeys() as $bucket => $rows)
                <section wire:key="b-{{ $bucket }}" class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <h2 @class(['px-3 py-2 text-sm font-semibold', 'bg-red-50 text-red-800' => $bucket == 0, 'bg-amber-50 text-amber-800' => $bucket > 0 && $bucket <= 30, 'bg-slate-50' => $bucket > 30])>
                        {{ $bucket == 0 ? 'Périmés - à retirer de la vente' : "Péremption sous {$bucket} jours" }}
                        · valeur {{ $money($rows->sum('value')) }} MRU
                    </h2>
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-3 py-2 text-start">Produit</th>
                                <th class="px-3 py-2 text-start">Lot</th>
                                <th class="px-3 py-2 text-start">Péremption</th>
                                <th class="px-3 py-2 text-end">Quantité</th>
                                <th class="px-3 py-2 text-end">Valeur (MRU)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($rows as $r)
                                <tr wire:key="e-{{ $r->batch->id }}">
                                    <td class="px-3 py-2">{{ $r->presentation->medicine->brand_name }} <span class="font-mono text-xs text-slate-500">{{ $r->presentation->sku }}</span></td>
                                    <td class="px-3 py-2 font-mono">{{ $r->batch->lot_number }} @if ($r->batch->status !== 'available') <span class="text-xs text-slate-500">({{ $r->batch->status }})</span> @endif</td>
                                    <td class="px-3 py-2">{{ $r->batch->expiry_date->format('d/m/Y') }}
                                        <span class="text-xs text-slate-500">{{ $r->days_left < 0 ? 'depuis '.abs($r->days_left).' j' : 'dans '.$r->days_left.' j' }}</span></td>
                                    <td class="px-3 py-2 text-end">{{ $r->quantity }} {{ $r->presentation->base_unit }}(s)</td>
                                    <td class="px-3 py-2 text-end">{{ $money($r->value) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @empty
                <p class="rounded-lg bg-white p-6 text-center text-sm text-slate-500 shadow-sm">Aucun lot proche de la péremption.</p>
            @endforelse
        @elseif ($tab === 'low_stock')
            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2 text-start">Produit</th>
                            <th class="px-3 py-2 text-end">Stock vendable</th>
                            <th class="px-3 py-2 text-end">Seuil</th>
                            <th class="px-3 py-2 text-end">À commander</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($lowStock as $p)
                            <tr wire:key="l-{{ $p->id }}" @class(['bg-red-50' => (int) $p->sellable_quantity <= 0])>
                                <td class="px-3 py-2"><a href="{{ route('catalogue.edit', $p->medicine_id) }}" class="text-emerald-800 hover:underline">{{ $p->medicine->brand_name }}</a> <span class="font-mono text-xs text-slate-500">{{ $p->sku }}</span></td>
                                <td class="px-3 py-2 text-end">{{ (int) $p->sellable_quantity <= 0 ? 'Rupture' : (int) $p->sellable_quantity }}</td>
                                <td class="px-3 py-2 text-end">{{ $p->reorder_threshold }}</td>
                                <td class="px-3 py-2 text-end">{{ $p->reorder_quantity ? intdiv($p->reorder_quantity, $p->units_per_pack).' boîte(s)' : '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">Aucun produit sous son seuil.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-slate-500">Quantités en unités de base ; stock vendable = emplacements vendables, lots disponibles non périmés, hors réservations.</p>
        @else
            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2 text-start">Caisse</th>
                            <th class="px-3 py-2 text-start">Clôturée le</th>
                            <th class="px-3 py-2 text-start">Par</th>
                            <th class="px-3 py-2 text-end">Écart (MRU)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($variances as $s)
                            <tr wire:key="c-{{ $s->id }}">
                                <td class="px-3 py-2"><a href="{{ route('sessions.show', $s) }}" class="text-emerald-800 hover:underline">{{ $s->register->code }}</a></td>
                                <td class="px-3 py-2">{{ $s->closed_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-3 py-2">{{ $s->closer?->name }}</td>
                                <td @class(['px-3 py-2 text-end font-semibold', 'text-red-700' => $s->difference < 0, 'text-amber-700' => $s->difference > 0])>{{ $s->difference > 0 ? '+' : '' }}{{ $money($s->difference) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">Aucun écart en attente de validation.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </main>
</div>
