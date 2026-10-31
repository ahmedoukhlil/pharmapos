<?php

namespace App\Livewire\Catalogue;

use App\Models\Category;
use App\Models\Medicine;
use App\Models\Presentation;
use App\Models\ProductBarcode;
use App\Services\Catalogue\CatalogueService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Fiche produit — PharmaPOS')]
class ProductEditor extends Component
{
    public ?Medicine $medicine = null;

    public array $form = [];

    /** Présentation en cours d'édition : null = aucune, '' = nouvelle, sinon son id. */
    public ?string $editing = null;

    public array $pres = [];

    public string $newCategory = '';

    public ?string $flash = null;

    public function mount(?Medicine $medicine = null): void
    {
        abort_unless(auth()->user()->hasPermission('catalogue.view'), 403);
        if ($medicine?->exists) {
            abort_unless($medicine->pharmacy_id === auth()->user()->pharmacy_id, 404);
            $this->medicine = $medicine;
        } else {
            $this->medicine = null;
        }

        $this->form = [
            'type' => $medicine?->type ?? 'medicine',
            'brand_name' => $medicine?->brand_name ?? '',
            'inn' => $medicine?->inn ?? '',
            'strength' => $medicine?->strength ?? '',
            'dosage_form' => $medicine?->dosage_form ?? '',
            'route' => $medicine?->route ?? '',
            'manufacturer' => $medicine?->manufacturer ?? '',
            'category_id' => $medicine?->category_id ?? '',
            'regulatory_status' => $medicine?->regulatory_status ?? '',
            'prescription_required' => (bool) ($medicine?->prescription_required ?? false),
            'storage_conditions' => $medicine?->storage_conditions ?? '',
        ];
    }

    public function save(CatalogueService $service)
    {
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->form);
        $data['prescription_required'] = (bool) $this->form['prescription_required'];
        $created = ! $this->medicine;
        $this->medicine = $service->saveMedicine(auth()->user(), $data, $this->medicine);

        if ($created) {
            return $this->redirectRoute('catalogue.edit', $this->medicine);
        }
        $this->flash = 'Fiche enregistrée.';

        return null;
    }

    public function addCategory(CatalogueService $service): void
    {
        $this->validate(['newCategory' => 'required|string|max:100'], [], ['newCategory' => 'catégorie']);
        $this->form['category_id'] = $service->saveCategory(auth()->user(), $this->newCategory)->id;
        $this->newCategory = '';
    }

    public function editPresentation(?string $id = null): void
    {
        $this->resetErrorBag();
        $p = $id ? $this->presentation($id) : null;
        $this->editing = $id ?? '';
        $this->pres = [
            'sku' => $p?->sku ?? '',
            'label' => $p?->label ?? '',
            'base_unit' => $p?->base_unit ?? 'comprimé',
            'units_per_pack' => $p?->units_per_pack ?? 1,
            'allow_split_sale' => (bool) ($p?->allow_split_sale ?? false),
            'unit_sale_price' => $p?->unit_sale_price ?? '',
            'purchase_price' => $p?->purchase_price ?? '',
            'sale_price' => $p?->sale_price ?? '',
            'reorder_threshold' => $p?->reorder_threshold ?? 0,
            'reorder_quantity' => $p?->reorder_quantity ?? 0,
        ];
    }

    public function savePresentation(CatalogueService $service): void
    {
        $data = $this->pres;
        $data['allow_split_sale'] = (bool) $data['allow_split_sale'];
        if ($data['unit_sale_price'] === '') {
            $data['unit_sale_price'] = null;
        }

        try {
            $service->savePresentation(auth()->user(), $this->medicine, $data, $this->editing ? $this->presentation($this->editing) : null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Erreurs rattachées aux champs pres.*
            throw \Illuminate\Validation\ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($m, $k) => ["pres.$k" => $m])->all());
        }
        $this->editing = null;
        $this->flash = 'Présentation enregistrée.';
    }

    /** Le code arrive du client : le scanner envoie Entrée avant toute synchronisation. */
    public function addBarcode(CatalogueService $service, string $presentationId, string $code): void
    {
        try {
            $service->addBarcode(auth()->user(), $this->presentation($presentationId), $code);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(["barcode.$presentationId" => $e->errors()['barcode']]);
        }
    }

    public function deactivateBarcode(CatalogueService $service, string $barcodeId): void
    {
        $service->deactivateBarcode(auth()->user(), ProductBarcode::findOrFail($barcodeId));
    }

    public function setStatus(CatalogueService $service, string $status, ?string $presentationId = null): void
    {
        $service->setStatus(auth()->user(), $presentationId ? $this->presentation($presentationId) : $this->medicine, $status);
        $this->medicine->refresh();
        $this->form['status'] = $this->medicine->status;
    }

    private function presentation(string $id): Presentation
    {
        return $this->medicine->presentations()->findOrFail($id);
    }

    public function render()
    {
        $pharmacyId = auth()->user()->pharmacy_id;

        return view('livewire.catalogue.product-editor', [
            'categories' => Category::where('pharmacy_id', $pharmacyId)->orderBy('name')->get(),
            'presentations' => $this->medicine
                ? $this->medicine->presentations()->with(['barcodes' => fn ($q) => $q->orderByDesc('is_active'),
                    'priceHistory' => fn ($q) => $q->latest('effective_at')->limit(10)])->withSum('stockBalances', 'quantity')->orderBy('sku')->get()
                : collect(),
            'canManage' => auth()->user()->hasPermission('catalogue.manage'),
            'canPrice' => auth()->user()->hasPermission('price.update'),
        ]);
    }
}
