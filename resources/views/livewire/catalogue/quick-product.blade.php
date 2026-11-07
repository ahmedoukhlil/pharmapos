@php
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none';
    $err = fn ($f) => $errors->first("form.$f");
    $isMed = ($form['type'] ?? 'medicine') === 'medicine';
@endphp

<div>
    @if ($open)
        <div @class(['fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4' => $mode === 'modal'])>
            <form wire:submit="save" @class([
                'w-full space-y-4 rounded-xl bg-white p-4 shadow-sm',
                'max-w-2xl my-8 shadow-xl' => $mode === 'modal',
            ])>
                <div class="flex items-start gap-2">
                    <div>
                        <h2 class="text-lg font-semibold">Nouveau produit</h2>
                        @if ($source)
                            <p class="text-sm text-slate-500">Pré-rempli depuis « {{ $source }} » - vérifiez avant d'enregistrer.</p>
                        @else
                            <p class="text-sm text-slate-500">L'essentiel pour vendre ; le reste est dans « Plus d'options » ou sur la fiche ensuite.</p>
                        @endif
                    </div>
                    @if ($mode === 'modal')
                        <button type="button" wire:click="close" class="ms-auto text-slate-500 hover:text-slate-800" title="Fermer">✕</button>
                    @endif
                </div>

                @if ($created)
                    <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ $created }}</p>
                @endif

                <div class="flex gap-2 text-sm">
                    @foreach (['medicine' => 'Médicament', 'parapharmacy' => 'Parapharmacie'] as $v => $l)
                        <label @class(['cursor-pointer rounded-full border px-3 py-1', 'border-emerald-700 bg-emerald-700 text-white' => $form['type'] === $v, 'border-slate-300' => $form['type'] !== $v])>
                            <input type="radio" wire:model.live="form.type" value="{{ $v }}" class="sr-only"> {{ $l }}
                        </label>
                    @endforeach
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium">Nom *</span>
                        <input wire:model="form.brand_name" class="{{ $input }}" placeholder="ex. Doliprane" @if ($mode === 'page') autofocus x-on:quick-product-next.window="$el.focus()" @endif>
                        @if ($err('brand_name')) <span class="text-xs text-red-700">{{ $err('brand_name') }}</span> @endif
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Dosage</span>
                        <input wire:model="form.strength" class="{{ $input }}" placeholder="ex. 500 mg">
                    </label>
                    @if ($isMed)
                        <label class="block sm:col-span-2">
                            <span class="text-sm font-medium">DCI *</span>
                            <input wire:model="form.inn" class="{{ $input }}" placeholder="ex. Paracétamol">
                            @if ($err('inn')) <span class="text-xs text-red-700">{{ $err('inn') }}</span> @endif
                        </label>
                    @endif
                    <label class="block">
                        <span class="text-sm font-medium">Forme</span>
                        <input wire:model.blur="form.dosage_form" list="qp-forms" class="{{ $input }}" placeholder="comprimé, sirop…">
                        <datalist id="qp-forms">
                            @foreach (['comprimé', 'comprimé effervescent', 'gélule', 'sirop', 'suspension buvable', 'sachet', 'ampoule', 'solution injectable', 'crème', 'pommade', 'gel', 'collyre', 'suppositoire', 'solution'] as $f)
                                <option value="{{ $f }}">
                            @endforeach
                        </datalist>
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block">
                        <span class="text-sm font-medium">Conditionnement *</span>
                        <input wire:model="form.label" class="{{ $input }}" placeholder="ex. Boîte de 16">
                        @if ($err('label')) <span class="text-xs text-red-700">{{ $err('label') }}</span> @endif
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Unités par boîte *</span>
                        <input wire:model.live.debounce.400ms="form.units_per_pack" type="number" min="1" class="{{ $input }}">
                        @if ($err('units_per_pack')) <span class="text-xs text-red-700">{{ $err('units_per_pack') }}</span> @endif
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Unité</span>
                        <input wire:model="form.base_unit" list="qp-units" class="{{ $input }}">
                        <datalist id="qp-units">
                            @foreach (['comprimé', 'gélule', 'flacon', 'sachet', 'ampoule', 'tube', 'unidose', 'unité'] as $u) <option value="{{ $u }}"> @endforeach
                        </datalist>
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block">
                        <span class="text-sm font-medium">Dernier prix d'achat (boîte)</span>
                        <input wire:model.live.debounce.400ms="form.purchase_price" inputmode="decimal" class="{{ $input }} text-end">
                        <span class="text-xs text-slate-500">Facultatif · mis à jour à chaque réception</span>
                        @if ($err('purchase_price')) <span class="text-xs text-red-700">{{ $err('purchase_price') }}</span> @endif
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Prix de vente (boîte) *</span>
                        <input wire:model.live.debounce.400ms="form.sale_price" inputmode="decimal" class="{{ $input }} text-end">
                        <span class="text-xs text-slate-500">{{ $salePriceTouched ? 'Saisi à la main' : (is_numeric($form['purchase_price'] ?? null) ? 'Proposé : achat × '.number_format($margin, 2, ',', ' ') : 'À saisir, ou proposé dès le prix d’achat') }}</span>
                        @if ($err('sale_price')) <span class="block text-xs text-red-700">{{ $err('sale_price') }}</span> @endif
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Code-barres</span>
                        <input wire:model="form.barcode" inputmode="numeric" class="{{ $input }} font-mono" placeholder="Scanner ou saisir">
                        @if ($err('barcode')) <span class="text-xs text-red-700">{{ $err('barcode') }}</span> @endif
                    </label>
                </div>

                <details class="rounded-lg bg-slate-50 p-3 text-sm">
                    <summary class="cursor-pointer font-medium">Plus d'options</summary>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div class="block">
                            <label for="qp-cat" class="font-medium">Catégorie</label>
                            <div class="flex gap-2">
                                <select id="qp-cat" wire:model="form.category_id" class="{{ $input }} min-w-0">
                                    <option value="">-</option>
                                    @foreach ($categories as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                                </select>
                                <input wire:model="newCategory" wire:keydown.enter.prevent="addCategory" placeholder="Nouvelle" class="{{ $input }} max-w-28">
                                <button type="button" wire:click="addCategory" class="mt-1 rounded-lg border border-slate-300 px-2 text-sm hover:bg-white" title="Ajouter la catégorie">+</button>
                            </div>
                            @error('newCategory') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                        </div>
                        <label class="block">
                            <span class="font-medium">Fabricant</span>
                            <input wire:model="form.manufacturer" class="{{ $input }}">
                        </label>
                        <label class="block">
                            <span class="font-medium">SKU</span>
                            <input wire:model="form.sku" class="{{ $input }}" placeholder="Vide = automatique">
                            @if ($err('sku')) <span class="text-xs text-red-700">{{ $err('sku') }}</span> @endif
                        </label>
                        @if ($isMed)
                            <label class="flex items-center gap-2 sm:col-span-3">
                                <input type="checkbox" wire:model="form.prescription_required" class="rounded border-slate-300"> Sur ordonnance
                            </label>
                        @endif
                    </div>
                </details>

                <div class="flex justify-end gap-2">
                    @if ($mode === 'modal')
                        <button type="button" wire:click="close" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">Annuler</button>
                    @endif
                    @if ($mode === 'page')
                        <button type="button" wire:click="save(true)" class="rounded-lg border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">Créer et saisir le suivant</button>
                    @endif
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Créer le produit</button>
                </div>
            </form>
        </div>
    @endif
</div>
