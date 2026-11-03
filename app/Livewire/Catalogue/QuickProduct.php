<?php

namespace App\Livewire\Catalogue;

use App\Models\Category;
use App\Services\Catalogue\CatalogueService;
use App\Support\ProductLabelParser;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Création rapide d'un produit vendable en un seul formulaire (produit + présentation + code-barres).
 * « page » : écran Nouveau produit ; « modal » : ouverte depuis un bordereau ou un scan inconnu.
 */
class QuickProduct extends Component
{
    public string $mode = 'page';

    public bool $open = false;

    /** Référence renvoyée à l'appelant (ex. index de la ligne du bordereau). */
    public ?string $ref = null;

    public array $form = [];

    /** Le prix de vente suit le prix d'achat × coefficient tant qu'il n'a pas été saisi à la main. */
    public bool $salePriceTouched = false;

    public string $source = '';

    public function mount(string $mode = 'page'): void
    {
        $this->mode = $mode;
        $this->openWith();
        $this->open = $mode === 'page';
    }

    /** @param array{designation?: string, barcode?: string, purchase_price?: float|string, type?: string} $prefill */
    #[On('quick-product-open')]
    public function openWith(array $prefill = [], ?string $ref = null): void
    {
        // Droits vérifiés à l'enregistrement (CatalogueService::quickCreate)
        $this->resetErrorBag();
        $this->ref = $ref;
        $this->salePriceTouched = false;
        $this->source = (string) ($prefill['designation'] ?? '');
        $parsed = $this->source !== '' ? ProductLabelParser::parse($this->source) : [];

        $this->form = [
            'type' => $prefill['type'] ?? 'medicine',
            'brand_name' => $parsed['brand_name'] ?? '',
            // Les grossistes écrivent souvent la DCI : proposée par défaut, à corriger si besoin
            'inn' => $parsed['brand_name'] ?? '',
            'strength' => $parsed['strength'] ?? '',
            'dosage_form' => $parsed['dosage_form'] ?? '',
            'label' => $parsed['label'] ?? 'Boîte',
            'base_unit' => $parsed['base_unit'] ?? 'comprimé',
            'units_per_pack' => $parsed['units_per_pack'] ?? 1,
            'purchase_price' => (string) ($prefill['purchase_price'] ?? ''),
            'sale_price' => '',
            'barcode' => (string) ($prefill['barcode'] ?? ''),
            'category_id' => '',
            'prescription_required' => false,
            'manufacturer' => '',
            'sku' => '',
        ];
        $this->suggestPrice();
        $this->open = true;
    }

    public function updated(string $field): void
    {
        if ($field === 'form.sale_price') {
            $this->salePriceTouched = $this->form['sale_price'] !== '';
        }
        if (in_array($field, ['form.purchase_price', 'form.type'], true)) {
            $this->suggestPrice();
        }
        if ($field === 'form.type' && $this->form['type'] === 'parapharmacy' && $this->form['inn'] === $this->form['brand_name']) {
            $this->form['inn'] = '';
        }
    }

    private function suggestPrice(): void
    {
        if (! $this->salePriceTouched && is_numeric($this->form['purchase_price'] ?? null)) {
            $price = CatalogueService::suggestedSalePrice(auth()->user()->pharmacy_id, $this->form['type'], (float) $this->form['purchase_price']);
            $this->form['sale_price'] = $price > 0 ? (string) $price : '';
        }
    }

    public function save(CatalogueService $service)
    {
        try {
            $presentation = $service->quickCreate(auth()->user(), $this->form);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        if ($this->mode === 'page') {
            session()->flash('flash', 'Produit créé : complétez la fiche si besoin (DCI, catégorie, code-barres…).');

            return $this->redirectRoute('catalogue.edit', $presentation->medicine_id);
        }

        $this->open = false;
        $this->dispatch('quick-product-created', presentationId: $presentation->id, ref: $this->ref);

        return null;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function render()
    {
        $pharmacyId = auth()->user()->pharmacy_id;

        return view('livewire.catalogue.quick-product', [
            'categories' => Category::where('pharmacy_id', $pharmacyId)->orderBy('name')->get(),
            'margin' => CatalogueService::margin($pharmacyId, $this->form['type'] ?? 'medicine'),
        ]);
    }
}
