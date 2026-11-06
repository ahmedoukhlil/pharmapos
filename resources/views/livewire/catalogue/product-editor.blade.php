@php
    $statuses = ['active' => 'Actif', 'suspended' => 'Suspendu', 'withdrawn' => 'Retiré', 'archived' => 'Archivé'];
    $priceTypes = ['purchase' => 'Achat', 'sale' => 'Vente', 'unit_sale' => 'Unité'];
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100';
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-5xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('catalogue.index') }}" class="text-sm text-emerald-800 hover:underline">← Catalogue</a>
            <h1 class="text-xl font-semibold">{{ $medicine ? $medicine->brand_name : 'Nouveau produit' }}</h1>
            @if ($medicine)
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $statuses[$medicine->status] }}</span>
                @if ($canManage)
                    <select x-on:change="$wire.setStatus($el.value); $el.value=''" class="ms-auto rounded-lg border border-slate-300 px-2 py-1 text-sm">
                        <option value="">Changer le statut…</option>
                        @foreach ($statuses as $k => $v)
                            @continue($k === $medicine->status)
                            <option value="{{ $k }}">{{ $v }}</option>
                        @endforeach
                    </select>
                @endif
            @endif
        </div>

        @if ($flash)
            <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $flash }}</p>
        @endif

        @if (! $medicine && $canManage)
            {{-- Création en un écran : produit + présentation + prix + code-barres --}}
            <livewire:catalogue.quick-product mode="page" />
        @else
        <form wire:submit="save" class="grid gap-3 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-2">
            <fieldset @disabled(! $canManage) class="contents">
                <label class="block">
                    <span class="text-sm font-medium">Type</span>
                    <select wire:model.live="form.type" class="{{ $input }}">
                        <option value="medicine">Médicament</option>
                        <option value="parapharmacy">Parapharmacie</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Nom commercial *</span>
                    <input wire:model="form.brand_name" class="{{ $input }}">
                    @error('brand_name') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="text-sm font-medium">DCI {{ $form['type'] === 'medicine' ? '*' : '' }}</span>
                    <input wire:model="form.inn" class="{{ $input }}">
                    @error('inn') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Dosage</span>
                    <input wire:model="form.strength" placeholder="500 mg" class="{{ $input }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Forme</span>
                    <input wire:model="form.dosage_form" placeholder="comprimé, sirop…" class="{{ $input }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Voie</span>
                    <input wire:model="form.route" placeholder="orale" class="{{ $input }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Fabricant</span>
                    <input wire:model="form.manufacturer" class="{{ $input }}">
                </label>
                <div class="block">
                    <label for="cat" class="text-sm font-medium">Catégorie</label>
                    <div class="flex gap-2">
                        <select id="cat" wire:model="form.category_id" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach ($categories as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                        @if ($canManage)
                            <input wire:model="newCategory" placeholder="Nouvelle" class="{{ $input }} max-w-32">
                            <button type="button" wire:click="addCategory" class="mt-1 rounded-lg border border-slate-300 px-2 text-sm">+</button>
                        @endif
                    </div>
                    @error('newCategory') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                </div>
                <label class="block">
                    <span class="text-sm font-medium">Conservation</span>
                    <input wire:model="form.storage_conditions" placeholder="< 25 °C, réfrigéré…" class="{{ $input }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Statut réglementaire</span>
                    <input wire:model="form.regulatory_status" class="{{ $input }}">
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="form.prescription_required"> Ordonnance obligatoire
                </label>
                @if ($canManage)
                    <div class="sm:col-span-2">
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                            {{ $medicine ? 'Enregistrer' : 'Créer le produit' }}
                        </button>
                    </div>
                @endif
            </fieldset>
        </form>

        @if ($medicine)
            <section class="space-y-3">
                <div class="flex items-center">
                    {{-- Un seul format (cas courant) : « Vente et stock » ; plusieurs boîtes du même produit : « Formats de vente » --}}
                    <h2 class="font-semibold">{{ $presentations->count() > 1 ? 'Formats de vente' : 'Vente et stock' }}</h2>
                    @if ($presentations->count() > 1)
                        <span class="ms-2 text-xs text-slate-500">même produit vendu en plusieurs boîtes (ex. boîte de 8 et de 16)</span>
                    @endif
                    @if ($canManage && $editing === null)
                        <button wire:click="editPresentation" class="ms-auto rounded-lg border border-emerald-700 px-3 py-1 text-sm text-emerald-800">{{ $presentations->isEmpty() ? 'Ajouter un format de vente' : 'Ajouter un autre format' }}</button>
                    @endif
                </div>

                @if ($editing !== null)
                    <form wire:submit="savePresentation" class="grid gap-3 rounded-lg border border-emerald-200 bg-white p-4 shadow-sm sm:grid-cols-4">
                        @foreach (['sku' => 'SKU', 'label' => 'Conditionnement *', 'base_unit' => 'Unité *', 'units_per_pack' => 'Unités / boîte *'] as $f => $l)
                            <label class="block">
                                <span class="text-sm font-medium">{{ $l }}</span>
                                <input wire:model="pres.{{ $f }}" @if ($f === 'units_per_pack') type="number" min="1" @endif @if ($f === 'sku') placeholder="Vide = généré automatiquement" @endif class="{{ $input }}">
                                @error("pres.$f") <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                            </label>
                        @endforeach
                        @foreach (['purchase_price' => "Dernier prix d'achat (boîte)", 'sale_price' => 'Prix de vente (boîte) *'] as $f => $l)
                            <label class="block">
                                <span class="text-sm font-medium">{{ $l }}</span>
                                <input wire:model="pres.{{ $f }}" type="number" step="0.01" min="0" @disabled($editing !== '' && ! $canPrice) class="{{ $input }}">
                                @error("pres.$f") <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                            </label>
                        @endforeach
                        <label class="flex items-center gap-2 self-end pb-2 text-sm">
                            <input type="checkbox" wire:model.live="pres.allow_split_sale"> Vente à l'unité
                        </label>
                        @if ($pres['allow_split_sale'])
                            <label class="block">
                                <span class="text-sm font-medium">Prix à l'unité *</span>
                                <input wire:model="pres.unit_sale_price" type="number" step="0.01" min="0" @disabled($editing !== '' && ! $canPrice) class="{{ $input }}">
                                @error('pres.unit_sale_price') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                            </label>
                        @endif
                        <label class="block">
                            <span class="text-sm font-medium">Seuil de réappro.</span>
                            <input wire:model="pres.reorder_threshold" type="number" min="0" class="{{ $input }}">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Qté à commander</span>
                            <input wire:model="pres.reorder_quantity" type="number" min="0" class="{{ $input }}">
                        </label>
                        <div class="flex items-end gap-2 sm:col-span-4">
                            <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Enregistrer le format</button>
                            <button type="button" wire:click="$set('editing', null)" class="rounded-lg px-3 py-2 text-sm">Annuler</button>
                        </div>
                    </form>
                @endif

                @forelse ($presentations as $p)
                    <article wire:key="p-{{ $p->id }}" class="space-y-2 rounded-lg bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-baseline gap-3">
                            <span class="font-semibold">{{ $p->label }}</span>
                            <span class="font-mono text-xs text-slate-500">{{ $p->sku }}</span>
                            <span class="text-xs text-slate-500">{{ $p->units_per_pack }} {{ $p->base_unit }}(s)/boîte · stock {{ (int) $p->stock_balances_sum_quantity }}</span>
                            @if ($p->status !== 'active')
                                <span class="rounded bg-amber-100 px-2 py-0.5 text-xs">{{ $statuses[$p->status] }}</span>
                            @endif
                            @if ($canManage)
                                <button wire:click="editPresentation('{{ $p->id }}')" class="ms-auto text-sm text-emerald-800 hover:underline">Modifier</button>
                                @if ($p->status === 'active')
                                    <button wire:click="setStatus('archived', '{{ $p->id }}')" wire:confirm="Archiver ce format ? Il ne sera plus vendable." class="text-sm text-slate-600 hover:underline">Archiver</button>
                                @else
                                    <button wire:click="setStatus('active', '{{ $p->id }}')" class="text-sm text-slate-600 hover:underline">Réactiver</button>
                                @endif
                            @endif
                        </div>
                        <div class="text-sm">
                            Achat {{ $money($p->purchase_price) }} · Vente <strong>{{ $money($p->sale_price) }}</strong>
                            @if ($p->allow_split_sale) · Unité {{ $money($p->unit_sale_price) }} @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-slate-500">Codes-barres :</span>
                            @foreach ($p->barcodes as $b)
                                @php $bad = $b->is_active ? \App\Support\Barcode::problem($b->barcode) : null; @endphp
                                <span wire:key="b-{{ $b->id }}" title="{{ $bad }}" @class(['rounded px-2 py-0.5 font-mono text-xs',
                                    'bg-slate-100' => $b->is_active && ! $bad, 'bg-red-100 text-red-800 ring-1 ring-red-300' => $bad,
                                    'bg-slate-50 text-slate-400 line-through' => ! $b->is_active])>
                                    @if ($bad) ⚠ @endif{{ $b->barcode }}
                                    @if ($canManage && $b->is_active)
                                        <button wire:click="deactivateBarcode('{{ $b->id }}')" wire:confirm="Désactiver ce code-barres ?" class="ms-1 text-red-700" title="Désactiver">×</button>
                                    @endif
                                </span>
                            @endforeach
                            @if ($canManage)
                                <form x-data="{ code: '' }" class="flex items-center gap-1"
                                      x-on:submit.prevent="if (code.trim()) { $wire.addBarcode('{{ $p->id }}', code.trim()); code = '' }">
                                    <input x-model="code" placeholder="Scanner ou saisir le code-barres" inputmode="numeric" autocomplete="off"
                                           class="w-60 rounded-lg border border-slate-300 px-2 py-1 font-mono text-xs focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none">
                                    <button class="rounded-lg bg-emerald-700 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-800">+ Ajouter</button>
                                </form>
                                @if ($p->barcodes->where('is_active', true)->contains(fn ($b) => \App\Support\Barcode::problem($b->barcode)))
                                    <span class="w-full text-xs text-red-700">⚠ Code(s) invalide(s), probablement abîmé(s) par Excel à l'import : retirez-les (×) et scannez le vrai code.</span>
                                @endif
                                @error("barcode.$p->id") <span class="w-full text-xs text-red-700">{{ $message }}</span> @enderror
                            @endif
                        </div>

                        @if ($p->priceHistory->isNotEmpty())
                            <details class="text-xs text-slate-600">
                                <summary class="cursor-pointer">Historique des prix</summary>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($p->priceHistory as $h)
                                        <li>{{ \Illuminate\Support\Carbon::parse($h->effective_at)->format('d/m/Y H:i') }} — {{ $priceTypes[$h->price_type] }} : {{ $money($h->old_price) }} → {{ $money($h->new_price) }} ({{ $h->reason }})</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </article>
                @empty
                    <p class="text-sm text-slate-500">Aucun format de vente (boîte, prix, code-barres) : ajoutez-en un pour pouvoir vendre ce produit.</p>
                @endforelse
            </section>
        @endif
        @endif
    </main>

    <x-reauth-modal :pending="$reauth" />
</div>
