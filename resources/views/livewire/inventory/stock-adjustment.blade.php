@php
    $input = 'rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none';
    $p = $this->presentation;
    $per = max(1, (int) $p?->units_per_pack);
    $fmt = fn (int $u) => \App\Services\Reports\ReportService::packs($u, $per, $p?->base_unit ?? 'unité');
    $statusLabels = ['available' => null, 'quarantined' => 'quarantaine', 'recalled' => 'rappel', 'blocked' => 'bloqué'];
@endphp

<div>
    <x-nav />

    <main class="mx-auto max-w-3xl space-y-4 p-4">
        <h1 data-tour-order="5" data-tour-title="Correction de stock" data-tour="Pour un cas ponctuel (casse, périmé à détruire, erreur de saisie, vol…) : choisissez le produit, le lot, la quantité et le motif. Chaque correction est tracée." class="text-xl font-semibold">Correction de stock</h1>

        @if (! $p)
            <form wire:submit="submitSearch" class="flex gap-2">
                <input wire:model.live.debounce.300ms="search" autofocus autocomplete="off" placeholder="Scanner ou rechercher (nom, DCI, code)"
                       class="{{ $input }} min-w-0 flex-1 py-3 text-base">
                <x-camera-scan method="scan" once />
            </form>
        @endif

        @if ($error) <p class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">{{ $error }}</p> @endif
        @if ($message) <p class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800" role="status">✓ {{ $message }}</p> @endif

        @if ($this->results->isNotEmpty())
            <ul class="divide-y divide-slate-100 rounded-xl bg-white shadow-sm ring-1 ring-slate-900/5">
                @foreach ($this->results as $r)
                    <li wire:key="res-{{ $r->id }}">
                        <button type="button" wire:click="choose('{{ $r->id }}')" class="block w-full px-4 py-3 text-start hover:bg-emerald-50">
                            <span class="font-medium">{{ trim($r->medicine->brand_name.' '.$r->medicine->strength) }}</span>
                            <span class="block text-xs text-slate-500">{{ $r->label }} · {{ $r->sku }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif (! $p && mb_strlen(trim($search)) >= 2)
            <p class="text-sm text-slate-500">Aucun produit pour « {{ $search }} ».</p>
        @endif

        @if ($p)
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-slate-900/5">
                <div class="flex items-start gap-3 border-b border-slate-100 p-4">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ trim($p->medicine->brand_name.' '.$p->medicine->strength) }}</p>
                        <p class="text-sm text-slate-500">{{ $p->label }}</p>
                    </div>
                    <button type="button" wire:click="clear" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Autre produit</button>
                </div>

                @if ($this->rows->isEmpty())
                    <p class="p-4 text-sm text-slate-500">Aucun stock pour ce produit : rien à corriger. Pour une entrée, passez par une réception.</p>
                @else
                    <form wire:submit="apply" class="space-y-4 p-4">
                        {{-- Lot et emplacement --}}
                        <fieldset class="space-y-1">
                            <legend class="mb-1 text-sm font-medium">Lot et emplacement</legend>
                            @foreach ($this->rows as $row)
                                <label wire:key="row-{{ $row->key }}" @class(['flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm',
                                        'border-emerald-600 bg-emerald-50' => $target === $row->key, 'border-slate-200' => $target !== $row->key])>
                                    <input type="radio" wire:model.live="target" value="{{ $row->key }}">
                                    <span class="font-mono font-medium">{{ $row->batch->lot_number }}</span>
                                    <span @class(['text-slate-500', 'font-semibold text-red-700' => $row->batch->expiry_date->isPast()])>exp. {{ $row->batch->expiry_date->format('m/Y') }}</span>
                                    @if ($statusLabels[$row->batch->status] ?? null)
                                        <span class="rounded-full bg-amber-100 px-2 text-xs text-amber-800">{{ $statusLabels[$row->batch->status] }}</span>
                                    @endif
                                    <span class="ms-auto text-end">{{ $row->location->name }} : <strong class="tabular-nums">{{ $fmt($row->quantity) }}</strong></span>
                                </label>
                            @endforeach
                        </fieldset>

                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            @foreach (['out' => 'Retirer du stock', 'in' => 'Ajouter au stock'] as $d => $text)
                                <button type="button" wire:click="$set('direction', '{{ $d }}')" @class(['rounded-full px-3 py-1.5 font-medium',
                                        'bg-red-700 text-white' => $direction === $d && $d === 'out', 'bg-emerald-700 text-white' => $direction === $d && $d === 'in',
                                        'bg-slate-100' => $direction !== $d])>{{ $text }}</button>
                            @endforeach
                            @if ($per > 1)
                                <span class="ms-auto text-slate-500">en</span>
                                @foreach (['pack' => 'boîtes', 'unit' => $p->base_unit.'s'] as $u => $text)
                                    <button type="button" wire:click="$set('unit', '{{ $u }}')" @class(['rounded-full px-3 py-1', 'bg-emerald-700 text-white' => $unit === $u, 'bg-slate-100' => $unit !== $u])>{{ $text }}</button>
                                @endforeach
                            @endif
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <label class="block">
                                <span class="text-sm font-medium">Quantité</span>
                                <input wire:model="quantity" type="number" min="1" inputmode="numeric" class="{{ $input }} mt-1 w-full text-center tabular-nums">
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="text-sm font-medium">Motif</span>
                                <select wire:model.live="reason" class="{{ $input }} mt-1 w-full">
                                    @foreach ($this->reasons as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach
                                </select>
                            </label>
                            <label class="block sm:col-span-3">
                                <span class="text-sm font-medium">Commentaire {{ $reason === 'other' ? '*' : '(facultatif)' }}</span>
                                <input wire:model="comment" maxlength="200" placeholder="ex. flacon tombé au rayon, n° de PV de destruction…" class="{{ $input }} mt-1 w-full">
                            </label>
                        </div>

                        <button @class(['w-full rounded-lg px-4 py-3 font-semibold text-white sm:w-auto',
                                'bg-red-700 hover:bg-red-800' => $direction === 'out', 'bg-emerald-700 hover:bg-emerald-800' => $direction === 'in'])>
                            {{ $direction === 'out' ? 'Retirer du stock' : 'Ajouter au stock' }}
                        </button>
                        <p class="text-xs text-slate-500">Votre mot de passe vous sera demandé. La correction est enregistrée avec votre nom, le motif et la valeur.</p>
                    </form>
                @endif
            </section>
        @endif

        @if ($this->recent->isNotEmpty())
            <section>
                <h2 class="mb-2 text-sm font-semibold text-slate-600">Dernières corrections</h2>
                <ul class="divide-y divide-slate-100 rounded-xl bg-white text-sm shadow-sm ring-1 ring-slate-900/5">
                    @foreach ($this->recent as $m)
                        @php $mp = $m->presentation; @endphp
                        <li wire:key="m-{{ $m->id }}" class="px-4 py-2">
                            <span class="font-medium">{{ trim($mp->medicine->brand_name.' '.$mp->medicine->strength) }}</span>
                            <span @class(['font-semibold tabular-nums', 'text-red-700' => $m->quantity < 0, 'text-emerald-700' => $m->quantity > 0])>
                                {{ $m->quantity > 0 ? '+' : '-' }}{{ \App\Services\Reports\ReportService::packs(abs((int) $m->quantity), max(1, (int) $mp->units_per_pack), $mp->base_unit) }}
                            </span>
                            <span class="block text-xs text-slate-500">{{ $m->reason }} · lot {{ $m->batch->lot_number }} · {{ $m->location->name }} · {{ $m->user->name }} · {{ $m->occurred_at->format('d/m H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </main>

    <x-reauth-modal :pending="$reauth" />
</div>
