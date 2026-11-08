<?php

namespace App\Livewire\Inventory;

use App\Models\Presentation;
use App\Models\StockMovement;
use App\Services\Catalogue\CatalogueService;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockEntryService;
use App\Support\ProductLookup;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Entrée de stock (stock initial, produits déjà en rayon) : scanner le code-barres ;
 * s'il est inconnu, chercher le produit par son nom et lui associer le code ;
 * puis saisir lot, péremption, quantité et insérer.
 */
#[Layout('components.layouts.app')]
#[Title('Entrée de stock - PharmaPOS')]
class StockEntry extends Component
{
    public string $search = '';

    /** Code scanné inconnu, en attente d'association à un produit. */
    public ?string $pendingBarcode = null;

    public string $nameSearch = '';

    public ?string $presentationId = null;

    public string $lot = '';

    public string $expiry = '';

    public string $quantity = '1';

    /** 'pack' (boîtes) ou 'unit' */
    public string $unit = 'pack';

    public string $locationId = '';

    public string $packCost = '';

    public ?string $error = null;

    public ?string $message = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('stock.adjust'), 403);
        $this->locationId = (string) ($this->locations->firstWhere('type', 'shelf')?->id ?? $this->locations->first()?->id);
    }

    #[Computed]
    public function locations()
    {
        return StockAdjustmentService::locations(auth()->user()->pharmacy_id);
    }

    #[Computed]
    public function results()
    {
        $term = $this->pendingBarcode ? $this->nameSearch : $this->search;

        return $this->presentationId || mb_strlen(trim($term)) < 2 ? collect() : ProductLookup::find(auth()->user()->pharmacy_id, $term, 12);
    }

    #[Computed]
    public function presentation(): ?Presentation
    {
        return $this->presentationId ? Presentation::with('medicine')->where('pharmacy_id', auth()->user()->pharmacy_id)->find($this->presentationId) : null;
    }

    #[Computed]
    public function recent()
    {
        return StockMovement::with(['batch', 'presentation.medicine', 'user', 'location'])
            ->where('pharmacy_id', auth()->user()->pharmacy_id)->where('source_type', 'stock_entry')
            ->latest('occurred_at')->limit(15)->get();
    }

    public function submitSearch(): void
    {
        if (trim($this->search) !== '') {
            $this->scan(trim($this->search));
        }
    }

    /** Douchette, caméra ou téléphone : produit connu → saisie ; code inconnu → association par nom. */
    #[On('remote-barcode')]
    public function scan(string $barcode): void
    {
        $term = trim($barcode);
        $this->error = $this->message = null;
        $match = ProductLookup::find(auth()->user()->pharmacy_id, $term);
        if ($match->count() === 1) {
            $this->choose($match->first()->id);

            return;
        }
        $this->presentationId = null;
        // Code (chiffres) introuvable : on propose de l'associer à un produit cherché par son nom
        if ($match->isEmpty() && preg_match('/^\d{6,14}$/', $term)) {
            $this->pendingBarcode = $term;
            $this->nameSearch = '';
            $this->search = '';

            return;
        }
        $this->search = $term;
        $this->error = $match->isEmpty() ? "Aucun produit pour « $term »." : null;
    }

    #[On('scanner-error')]
    public function scannerError(string $message): void
    {
        $this->error = $message;
    }

    /** Produit choisi ; si un code inconnu attend, il lui est associé. */
    public function choose(string $id): void
    {
        $p = Presentation::where('pharmacy_id', auth()->user()->pharmacy_id)->findOrFail($id);
        if ($this->pendingBarcode) {
            if (! auth()->user()->hasPermission('catalogue.manage')) {
                $this->error = "Code « {$this->pendingBarcode} » non associé : droit « Gérer le catalogue » nécessaire. Le stock peut quand même être ajouté.";
            } else {
                try {
                    app(CatalogueService::class)->addBarcode(auth()->user(), $p, $this->pendingBarcode);
                    $this->message = "Code « {$this->pendingBarcode} » associé : il sera reconnu au prochain scan.";
                } catch (ValidationException $e) {
                    $this->error = "Code « {$this->pendingBarcode} » : ".collect($e->errors())->flatten()->first();

                    return;
                }
            }
        }
        $this->presentationId = $p->id;
        $this->pendingBarcode = null;
        $this->search = $this->nameSearch = '';
        $this->lot = $this->expiry = '';
        $this->quantity = '1';
        $this->unit = $p->units_per_pack > 1 ? 'pack' : 'unit';
        $this->packCost = (float) $p->purchase_price > 0 ? (string) $p->purchase_price : '';
    }

    public function cancelLink(): void
    {
        $this->reset('pendingBarcode', 'nameSearch');
    }

    public function clear(): void
    {
        $this->reset('presentationId', 'search', 'pendingBarcode', 'nameSearch', 'lot', 'expiry', 'error', 'message');
        $this->quantity = '1';
    }

    public function insert(StockEntryService $service): void
    {
        $this->error = $this->message = null;
        $p = $this->presentation;
        if (! $p) {
            return;
        }
        $qty = (int) $this->quantity;
        $per = $this->unit === 'pack' ? max(1, (int) $p->units_per_pack) : 1;
        try {
            $service->add(auth()->user(), $p->id, $this->lot, $this->expiry, $qty * $per, $this->locationId,
                is_numeric($this->packCost) ? (float) $this->packCost : null);
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->message = 'Inséré : '.$qty.' '.($this->unit === 'pack' ? 'boîte(s)' : 'unité(s)').' de '
            .trim($p->medicine->brand_name.' '.$p->medicine->strength).(trim($this->lot) !== '' ? ', lot '.strtoupper(trim($this->lot)) : ', sans n° de lot').'. Scannez le produit suivant.';
        $this->reset('presentationId', 'lot', 'expiry');
        $this->quantity = '1';
        unset($this->recent);
    }

    public function render()
    {
        return view('livewire.inventory.stock-entry');
    }
}
