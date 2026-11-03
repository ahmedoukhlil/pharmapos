<?php

namespace App\Livewire\Purchasing;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Purchasing\PurchaseOrderException;
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

    /** Formulaire d'acompte (null = fermé). */
    public ?array $advance = null;

    public ?string $error = null;

    public ?string $message = null;

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function openAdvance(?string $supplierId = null): void
    {
        $this->reset('error', 'message');
        $this->advance = [
            'supplier_id' => $supplierId ?? (string) Supplier::where('pharmacy_id', auth()->user()->pharmacy_id)->where('is_active', true)->orderBy('name')->value('id'),
            'amount' => '', 'method' => 'transfer', 'reference' => '', 'paid_on' => now()->toDateString(),
        ];
    }

    public function saveAdvance(PurchaseInvoiceService $service): void
    {
        $this->reset('error', 'message');
        $supplier = Supplier::where('pharmacy_id', auth()->user()->pharmacy_id)->findOrFail($this->advance['supplier_id']);
        try {
            $service->advance(auth()->user(), $supplier, $this->advance);
        } catch (PurchaseOrderException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->advance = null;
        $this->message = "Acompte enregistré pour {$supplier->name} : imputez-le depuis la facture concernée.";
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

        // Dette par fournisseur (factures non soldées), acomptes non imputés à côté
        $advances = SupplierPayment::where('pharmacy_id', $pharmacyId)->whereNull('purchase_invoice_id')
            ->groupBy('supplier_id')->pluck(DB::raw('SUM(amount)'), 'supplier_id');
        $debts = PurchaseInvoice::join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->where('purchase_invoices.pharmacy_id', $pharmacyId)
            ->whereIn('purchase_invoices.status', ['open', 'partially_paid'])
            ->groupBy('suppliers.id', 'suppliers.name')
            ->select('suppliers.id', 'suppliers.name', DB::raw('SUM(total_amount - paid_amount) as balance'),
                DB::raw("SUM(CASE WHEN due_date < '$today' THEN total_amount - paid_amount ELSE 0 END) as overdue"))
            ->orderByDesc('balance')->get()
            ->each(fn ($d) => $d->advances = (float) ($advances[$d->id] ?? 0));

        return view('livewire.purchasing.invoice-index', [
            'invoices' => $invoices, 'debts' => $debts, 'today' => $today,
            'suppliers' => $this->advance ? Supplier::where('pharmacy_id', $pharmacyId)->orderBy('name')->get() : collect(),
            'methods' => PurchaseInvoiceService::PAYMENT_METHODS,
        ]);
    }
}
