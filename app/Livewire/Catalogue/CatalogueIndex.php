<?php

namespace App\Livewire\Catalogue;

use App\Models\Medicine;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Catalogue — PharmaPOS')]
class CatalogueIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = 'active';

    public function updating($name): void
    {
        $this->resetPage();
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
        ]);
    }
}
