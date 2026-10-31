<?php

namespace App\Livewire\Purchasing;

use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Factures fournisseurs — PharmaPOS')]
class InvoiceIndex extends Component
{
    use WithPagination;

    /** unpaid | overdue | paid | all */
    #[Url]
    public string $filter = 'unpaid';

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(auth()->user()->hasPermission('purchase.invoice'), 403);
        $pharmacyId = auth()->user()->pharmacy_id;
        $today = now()->toDateString();

        $invoices = PurchaseInvoice::with('supplier')->where('pharmacy_id', $pharmacyId)
            ->when($this->filter === 'unpaid', fn ($q) => $q->whereIn('status', ['open', 'partially_paid']))
            ->when($this->filter === 'overdue', fn ($q) => $q->whereIn('status', ['open', 'partially_paid'])->where('due_date', '<', $today))
            ->when($this->filter === 'paid', fn ($q) => $q->where('status', 'paid'))
            ->orderBy('due_date')->paginate(25);

        // Dette par fournisseur (factures non soldées)
        $debts = PurchaseInvoice::join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->where('purchase_invoices.pharmacy_id', $pharmacyId)
            ->whereIn('purchase_invoices.status', ['open', 'partially_paid'])
            ->groupBy('suppliers.id', 'suppliers.name')
            ->select('suppliers.name', DB::raw('SUM(total_amount - paid_amount) as balance'),
                DB::raw("SUM(CASE WHEN due_date < '$today' THEN total_amount - paid_amount ELSE 0 END) as overdue"))
            ->orderByDesc('balance')->get();

        return view('livewire.purchasing.invoice-index', ['invoices' => $invoices, 'debts' => $debts, 'today' => $today]);
    }
}
