@php
    $input = 'rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none';
    $p = $this->presentation;
    $per = max(1, (int) $p?->units_per_pack);
@endphp

<div>
    <x-nav />

    <main class="mx-auto max-w-3xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 data-tour-order="5" data-tour-title="Entrée de stock" data-tour="Pour le stock initial ou des produits déjà en rayon : scannez le code-barres. S'il est inconnu, cherchez le produit par son nom pour lui associer le code, puis saisissez lot, péremption et quantité." class="text-xl font-semibold">Entrée de stock</h1>
            <span class="ms-auto"><livewire:scanner.scanner-panel mode="stock" :context-id="auth()->user()->pharmacy_id" key="scanner-stock" /></span>
        </div>

        @if (! $p && ! $pendingBarcode)
            <form wire:submit="submitSearch" class="flex gap-2">
                <input wire:model.live.debounce.300ms="search" autofocus autocomplete="off" placeholder="Scanner le code-barres ou rechercher (nom, DCI, code)"
                       class="{{ $input }} min-w-0 flex-1 py-3 text-base">
                <x-camera-scan method="scan" once />
            </form>
        @endif

        @if ($error) <p class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">{{ $error }}</p> @endif
        @if ($message) <p class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800" role="status">✓ {{ $message }}</p> @endif

        @if ($pendingBarcode)
            <section class="space-y-3 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200">
                <p class="text-sm"><strong>Code inconnu : <span class="font-mono">{{ $pendingBarcode }}</span></strong><br>
                    Cherchez le produit par son nom : le code lui sera associé, puis vous saisirez la quantité.</p>
                <div class="flex gap-2">
                    <input wire:model.live.debounce.300ms="nameSearch" autofocus autocomplete="off" placeholder="Nom commercial ou DCI, ex. doliprane"
                           class="{{ $input }} min-w-0 flex-1 bg-white py-3 text-base">
                    <button type="button" wire:click="cancelLink" class="rounded-lg border border-slate-300 bg-white px-3 text-sm">Annuler</button>
                </div>
                @if (mb_strlen(trim($nameSearch)) >= 2 && $this->results->isEmpty())
                    <p class="text-sm text-slate-600">Aucun produit pour « {{ $nameSearch }} ». Créez-le d'abord dans le <a href="{{ route('catalogue.index') }}" class="text-emerald-800 underline">catalogue</a>.</p>
                @endif
            </section>
        @endif

        @if ($this->results->isNotEmpty())
            <ul class="divide-y divide-slate-100 rounded-xl bg-white shadow-sm ring-1 ring-slate-900/5">
                @foreach ($this->results as $r)
                    <li wire:key="res-{{ $r->id }}">
                        <button type="button" wire:click="choose('{{ $r->id }}')" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-emerald-50">
                            <span class="min-w-0 flex-1">
                                <span class="font-medium">{{ trim($r->medicine->brand_name.' '.$r->medicine->strength) }}</span>
                                <span class="block text-xs text-slate-500">{{ $r->label }} · {{ $r->sku }}</span>
                            </span>
                            @if ($pendingBarcode) <span class="text-xs font-semibold text-emerald-800">Associer</span> @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif (! $p && ! $pendingBarcode && mb_strlen(trim($search)) >= 2)
            <p class="text-sm text-slate-500">Aucun produit pour « {{ $search }} ».</p>
        @endif

        @if ($p)
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-slate-900/5">
                <div class="flex items-start gap-3 border-b border-slate-100 p-4">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ trim($p->medicine->brand_name.' '.$p->medicine->strength) }}</p>
                        <p class="text-sm text-slate-500">{{ $p->label }} · {{ $p->sku }}</p>
                    </div>
                    <button type="button" wire:click="clear" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Autre produit</button>
                </div>

                <form wire:submit="insert" class="space-y-4 p-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium">N° de lot *</span>
                            <input wire:model="lot" autofocus autocomplete="off" class="{{ $input }} mt-1 w-full font-mono uppercase">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Péremption *</span>
                            <input wire:model="expiry" placeholder="MM/AAAA" inputmode="numeric" class="{{ $input }} mt-1 w-full">
                        </label>
                        <div class="block">
                            <span class="text-sm font-medium">Quantité *</span>
                            <div class="mt-1 flex gap-2">
                                <input wire:model="quantity" type="number" min="1" inputmode="numeric" class="{{ $input }} w-24 text-center tabular-nums">
                                @if ($per > 1)
                                    @foreach (['pack' => 'boîtes', 'unit' => $p->base_unit.'s'] as $u => $text)
                                        <button type="button" wire:click="$set('unit', '{{ $u }}')" @class(['rounded-full px-3 py-1 text-sm', 'bg-emerald-700 text-white' => $unit === $u, 'bg-slate-100' => $unit !== $u])>{{ $text }}</button>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                        <label class="block">
                            <span class="text-sm font-medium">Emplacement</span>
                            <select wire:model="locationId" class="{{ $input }} mt-1 w-full">
                                @foreach ($this->locations as $l) <option value="{{ $l->id }}">{{ $l->name }}</option> @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Prix d'achat par boîte</span>
                            <input wire:model="packCost" type="number" min="0" step="0.01" class="{{ $input }} mt-1 w-full tabular-nums">
                        </label>
                    </div>
                    <button class="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white hover:bg-emerald-800 sm:w-auto">Insérer dans le stock</button>
                </form>
            </section>
        @endif

        @if ($this->recent->isNotEmpty())
            <section>
                <h2 class="mb-2 text-sm font-semibold text-slate-600">Dernières entrées</h2>
                <ul class="divide-y divide-slate-100 rounded-xl bg-white text-sm shadow-sm ring-1 ring-slate-900/5">
                    @foreach ($this->recent as $m)
                        @php $mp = $m->presentation; @endphp
                        <li wire:key="m-{{ $m->id }}" class="px-4 py-2">
                            <span class="font-medium">{{ trim($mp->medicine->brand_name.' '.$mp->medicine->strength) }}</span>
                            <span class="font-semibold text-emerald-700 tabular-nums">+{{ \App\Services\Reports\ReportService::packs((int) $m->quantity, max(1, (int) $mp->units_per_pack), $mp->base_unit) }}</span>
                            <span class="block text-xs text-slate-500">lot {{ $m->batch->lot_number }} · exp. {{ $m->batch->expiry_date->format('m/Y') }} · {{ $m->location->name }} · {{ $m->user->name }} · {{ $m->occurred_at->format('d/m H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </main>
</div>
