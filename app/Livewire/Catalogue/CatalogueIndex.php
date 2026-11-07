<?php

namespace App\Livewire\Catalogue;

use App\Models\Medicine;
use App\Services\Catalogue\CatalogueService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Catalogue - PharmaPOS')]
class CatalogueIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = 'active';

    /** Coefficients de marge affichés dans le panneau « Marge par défaut ». */
    public ?array $margins = null;

    public ?string $marginMessage = null;

    public function updating($name): void
    {
        if (! str_starts_with($name, 'margins')) {
            $this->resetPage();
        }
    }

    public function editMargins(): void
    {
        $id = auth()->user()->pharmacy_id;
        foreach (['medicine', 'parapharmacy'] as $type) {
            $this->margins[$type] = array_map(fn ($t) => ['up_to' => $t['up_to'] === null ? '' : (string) $t['up_to'], 'coef' => (string) $t['coef']],
                CatalogueService::marginTiers($id, $type));
        }
        $this->marginMessage = null;
    }

    /** Ajoute une tranche avant « au-delà », seuil proposé au double du précédent. */
    public function addTier(string $type): void
    {
        $rows = $this->margins[$type];
        if (count($rows) >= CatalogueService::MAX_MARGIN_TIERS) {
            return;
        }
        $previous = count($rows) > 1 ? (float) str_replace(',', '.', $rows[count($rows) - 2]['up_to']) : 0;
        array_splice($rows, count($rows) - 1, 0, [['up_to' => (string) ($previous > 0 ? $previous * 2 : 100), 'coef' => end($rows)['coef']]]);
        $this->margins[$type] = $rows;
    }

    public function removeTier(string $type, int $i): void
    {
        $rows = $this->margins[$type];
        if (count($rows) > 1 && $i < count($rows) - 1) {
            array_splice($rows, $i, 1);
            $this->margins[$type] = $rows;
        }
    }

    public function saveMargins(CatalogueService $service): void
    {
        $service->setMarginTiers(auth()->user(), $this->margins);
        $this->margins = null;
        $this->marginMessage = 'Coefficients enregistrés : ils s\'appliquent aux prochains produits créés.';
    }

    public function render()
    {
        abort_unless(auth()->user()->hasPermission('catalogue.view'), 403);
        $term = trim($this->search);

        $medicines = Medicine::with(['category', 'presentations.barcodes'])
            ->where('pharmacy_id', auth()->user()->pharmacy_id)
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('brand_name', 'like', "%$term%")->orWhere('inn', 'like', "%$term%")
                ->orWhereHas('presentations', fn ($p) => $p->where('sku', 'like', "%$term%")
                    ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $term)))))
            ->orderBy('brand_name')->paginate(25);

        return view('livewire.catalogue.catalogue-index', [
            'medicines' => $medicines,
            'canManage' => auth()->user()->hasPermission('catalogue.manage'),
            'canPrice' => auth()->user()->hasPermission('price.update'),
            'currentMargins' => ['medicine' => CatalogueService::marginTiers(auth()->user()->pharmacy_id, 'medicine'), 'parapharmacy' => CatalogueService::marginTiers(auth()->user()->pharmacy_id, 'parapharmacy')],
        ]);
    }
}
