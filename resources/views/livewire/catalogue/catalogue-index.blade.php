@php
    $statuses = ['active' => 'Actif', 'suspended' => 'Suspendu', 'withdrawn' => 'Retiré', 'archived' => 'Archivé'];
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">Catalogue</h1>
            @if ($canManage)
                <a href="{{ route('catalogue.import') }}" class="ms-auto rounded-lg border border-emerald-700 px-4 py-2 text-sm text-emerald-800">Importer</a>
                <a href="{{ route('catalogue.create') }}" class=" rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Nouveau produit</a>
            @endif
        </div>

        @if ($canPrice)
            <div class="flex flex-wrap items-center gap-3 rounded-lg bg-white px-4 py-2 text-sm shadow-sm">
                <span class="font-medium">Marge par défaut</span>
                @if ($margins === null)
                    <span class="text-slate-600">Médicaments × {{ number_format($currentMargins['medicine'], 2, ',', ' ') }} · Parapharmacie × {{ number_format($currentMargins['parapharmacy'], 2, ',', ' ') }}</span>
                    <span class="text-xs text-slate-500">(prix de vente proposé = prix d'achat × coefficient)</span>
                    <button wire:click="editMargins" class="ms-auto text-emerald-800 hover:underline">Modifier</button>
                @else
                    <form wire:submit="saveMargins" class="flex flex-wrap items-center gap-2">
                        <label>Médicaments × <input wire:model="margins.medicine" inputmode="decimal" class="w-20 rounded border border-slate-300 px-2 py-1 text-end"></label>
                        <label>Parapharmacie × <input wire:model="margins.parapharmacy" inputmode="decimal" class="w-20 rounded border border-slate-300 px-2 py-1 text-end"></label>
                        <button class="rounded-lg bg-emerald-700 px-3 py-1 font-semibold text-white">Enregistrer</button>
                        <button type="button" wire:click="$set('margins', null)" class="text-slate-600">Annuler</button>
                    </form>
                    @error('margin_medicine') <span class="w-full text-xs text-red-700">{{ $message }}</span> @enderror
                    @error('margin_parapharmacy') <span class="w-full text-xs text-red-700">{{ $message }}</span> @enderror
                @endif
                @if ($marginMessage) <span class="w-full text-emerald-800">{{ $marginMessage }}</span> @endif
            </div>
        @endif

        <div class="flex flex-wrap gap-3 rounded-lg bg-white p-4 shadow-sm">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Nom, DCI, SKU ou code-barres"
                   x-init="$nextTick(() => $el.focus())"
                   class="min-w-64 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <select wire:model.live="type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">Tous types</option>
                <option value="medicine">Médicaments</option>
                <option value="parapharmacy">Parapharmacie</option>
            </select>
            <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">Tous statuts</option>
                @foreach ($statuses as $k => $v)
                    <option value="{{ $k }}">{{ $v }}</option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">Produit</th>
                        <th class="px-3 py-2 text-start">Catégorie</th>
                        <th class="px-3 py-2 text-start">Présentations</th>
                        <th class="px-3 py-2 text-start">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($medicines as $m)
                        <tr wire:key="{{ $m->id }}" class="hover:bg-slate-50">
                            <td class="px-3 py-2">
                                <a href="{{ route('catalogue.edit', $m) }}" class="font-medium text-emerald-800 hover:underline">{{ $m->brand_name }} {{ $m->strength }}</a>
                                <div class="text-xs text-slate-500">
                                    {{ $m->type === 'medicine' ? 'Médicament' : 'Parapharmacie' }}@if ($m->inn) · {{ $m->inn }}@endif
                                    @if ($m->prescription_required) · <span class="text-amber-700">Ordonnance</span>@endif
                                </div>
                            </td>
                            <td class="px-3 py-2">{{ $m->category?->name }}</td>
                            <td class="px-3 py-2">
                                @foreach ($m->presentations as $p)
                                    <div><span class="font-mono text-xs">{{ $p->sku }}</span> {{ number_format((float) $p->sale_price, 2, ',', ' ') }} MRU</div>
                                @endforeach
                            </td>
                            <td class="px-3 py-2">{{ $statuses[$m->status] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">Aucun produit.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $medicines->links() }}
    </main>
</div>
