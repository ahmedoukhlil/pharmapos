<?php

namespace App\Livewire\Purchasing;

use App\Models\GoodsReceipt;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Réceptions — PharmaPOS')]
class ReceiptIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'all';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $receipts = GoodsReceipt::with(['supplier', 'receiver'])->withCount('lines')
            ->where('pharmacy_id', auth()->user()->pharmacy_id)
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest('received_at')
            ->paginate(20);

        return view('livewire.purchasing.receipt-index', [
            'receipts' => $receipts,
            'canManage' => auth()->user()->hasPermission('purchase.manage'),
        ]);
    }
}
