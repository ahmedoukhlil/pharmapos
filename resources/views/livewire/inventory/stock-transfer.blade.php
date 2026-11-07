@php
    $input = 'rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none';
    $names = $this->locations->keyBy('id');
    $p = $this->presentation;
    $per = max(1, (int) $p?->units_per_pack);
    $fmt = fn (int $u) => \App\Services\Reports\ReportService::packs($u, $per, $p?->base_unit ?? 'unité');
@endphp

<div>
    <x-nav />

    <main class="mx-auto max-w-3xl space-y-4 p-4">
        <h1 data-tour-order="5" data-tour-title="Transfert de stock" data-tour="Déplacez des boîtes entre la Réserve et le Rayon : scannez le produit, choisissez le sens puis la quantité par lot." class="text-xl font-semibold">Transfert de stock</h1>

        {{-- Sens du transfert --}}
        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
            <div class="flex items-end gap-2">
                <label class="min-w-0 flex-1">
                    <span class="text-xs font-medium text-slate-500">De</span>
                    <select wire:model.live="fromId" class="{{ $input }} mt-1 w-full">
                        @foreach ($this->locations as $l) <option value="{{ $l->id }}">{{ $l->name }}</option> @endforeach
                    </select>
                </label>
                <button type="button" wire:click="swap" title="Inverser le sens" class="mb-0.5 rounded-full border border-slate-300 p-2 hover:bg-slate-50">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M7 4 3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/></svg>
                </button>
                <label class="min-w-0 flex-1">
                    <span class="text-xs font-medium text-slate-500">Vers</span>
                    <select wire:model.live="toId" class="{{ $input }} mt-1 w-full">
                        @foreach ($this->locations as $l) <option value="{{ $l->id }}">{{ $l->name }}</option> @endforeach
                    </select>
                </label>
            </div>
            @if ($fromId === $toId) <p class="mt-2 text-sm text-amber-800">Choisissez deux emplacements différents.</p> @endif
        </section>

        {{-- Produit : recherche, douchette ou caméra --}}
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

                @if ($per > 1)
                    <div class="flex items-center gap-2 px-4 pt-3 text-sm">
                        <span class="text-slate-500">Quantité en</span>
                        @foreach (['pack' => 'boîtes', 'unit' => $p->base_unit.'s'] as $u => $text)
                            <button type="button" wire:click="$set('unit', '{{ $u }}')" @class(['rounded-full px-3 py-1', 'bg-emerald-700 text-white' => $unit === $u, 'bg-slate-100' => $unit !== $u])>{{ $text }}</button>
                        @endforeach
                    </div>
                @endif

                <ul class="divide-y divide-slate-100">
                    @forelse ($this->batches as $b)
                        @php $batch = $b['batch']; $here = (int) ($b['stock'][$fromId] ?? 0); @endphp
                        <li wire:key="b-{{ $batch->id }}" class="space-y-2 p-4">
                            <div class="flex flex-wrap items-baseline gap-x-3">
                                <span class="font-mono font-medium">{{ $batch->lot_number }}</span>
                                <span class="text-sm text-slate-500">exp. {{ $batch->expiry_date->format('m/Y') }}</span>
                            </div>
                            <div class="flex flex-wrap gap-x-4 text-sm">
                                @foreach ($this->locations as $l)
                                    <span @class(['font-semibold' => $l->id === $fromId, 'text-slate-500' => $l->id !== $fromId])>{{ $l->name }} : {{ $fmt((int) ($b['stock'][$l->id] ?? 0)) }}</span>
                                @endforeach
                            </div>
                            @if ($here > 0 && $fromId !== $toId)
                                <form wire:submit="transfer('{{ $batch->id }}')" class="flex gap-2">
                                    <input wire:model="quantities.{{ $batch->id }}" type="number" min="1" inputmode="numeric" placeholder="Qté"
                                           class="{{ $input }} w-24 text-center tabular-nums" aria-label="Quantité à transférer, lot {{ $batch->lot_number }}">
                                    <button type="button" wire:click="takeAll('{{ $batch->id }}')" class="rounded-lg border border-slate-300 px-3 text-sm hover:bg-slate-50">Tout</button>
                                    <button class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                        → {{ $names[$toId]->name ?? '' }}
                                    </button>
                                </form>
                            @else
                                <p class="text-xs text-slate-500">Rien à transférer depuis {{ $names[$fromId]->name ?? '' }}.</p>
                            @endif
                        </li>
                    @empty
                        <li class="p-4 text-sm text-slate-500">Aucun stock vendable pour ce produit.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($this->recent->isNotEmpty())
            <section>
                <h2 class="mb-2 text-sm font-semibold text-slate-600">Derniers transferts</h2>
                <ul class="divide-y divide-slate-100 rounded-xl bg-white text-sm shadow-sm ring-1 ring-slate-900/5">
                    @foreach ($this->recent as $m)
                        @php $mp = $m->presentation; @endphp
                        <li wire:key="m-{{ $m->id }}" class="px-4 py-2">
                            <span class="font-medium">{{ trim($mp->medicine->brand_name.' '.$mp->medicine->strength) }}</span>
                            · {{ \App\Services\Reports\ReportService::packs((int) $m->quantity, max(1, (int) $mp->units_per_pack), $mp->base_unit) }}
                            <span class="block text-xs text-slate-500">{{ str_replace('Transfert ', '', $m->reason) }} · lot {{ $m->batch->lot_number }} · {{ $m->user->name }} · {{ $m->occurred_at->format('d/m H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </main>
</div>
