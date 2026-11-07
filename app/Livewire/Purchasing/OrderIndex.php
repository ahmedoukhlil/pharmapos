<?php

namespace App\Livewire\Purchasing;

use App\Models\PurchaseOrder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Commandes - PharmaPOS')]
class OrderIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(auth()->user()->hasPermission('purchase.manage'), 403);

        return view('livewire.purchasing.order-index', [
            'orders' => PurchaseOrder::with('supplier')->withCount('lines')
                ->where('pharmacy_id', auth()->user()->pharmacy_id)
                ->when($this->status, fn ($q) => $q->where('status', $this->status))
                ->latest()->paginate(20),
        ]);
    }
}
