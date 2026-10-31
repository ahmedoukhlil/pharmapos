<?php

namespace App\Livewire\Purchasing;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Purchasing\PurchaseOrderException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Saisie d'une facture (création) puis consultation : rapprochement et règlements.
 */
#[Layout('components.layouts.app')]
#[Title('Facture fournisseur — PharmaPOS')]
class InvoiceEditor extends Component
{
    public ?PurchaseInvoice $invoice = null;

    public array $form = [];

    public array $receiptIds = [];

    public array $payment = [];

    public ?string $error = null;

    public ?string $message = null;

    public function mount(?PurchaseInvoice $invoice = null): void
    {
        abort_unless(auth()->user()->hasPermission('purchase.invoice'), 403);

        if ($invoice?->exists) {
            abort_unless($invoice->pharmacy_id === auth()->user()->pharmacy_id, 404);
            $this->invoice = $invoice;
            $this->resetPayment();

            return;
        }

        $this->form = [
            'supplier_id' => (string) Supplier::where('pharmacy_id', auth()->user()->pharmacy_id)->where('is_active', true)->orderBy('name')->value('id'),
            'supplier_invoice_number' => '',
            'invoice_date' => now()->toDateString(),
            'due_date' => '',
            'total_amount' => '',
        ];
    }

    public function updatedFormSupplierId(): void
    {
        $this->receiptIds = [];
    }

    /** Pré-remplit le montant avec la valeur des réceptions cochées. */
    public function useReceiptsTotal(PurchaseInvoiceService $service): void
    {
        $this->form['total_amount'] = number_format($this->selectedValue($service), 2, '.', '');
    }

    public function create(PurchaseInvoiceService $service)
    {
        $this->error = null;
        try {
            $invoice = $service->create(auth()->user(), $this->form + ['receipt_ids' => $this->receiptIds]);
        } catch (PurchaseOrderException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('invoices.show', $invoice);
    }

    public function pay(PurchaseInvoiceService $service): void
    {
        $this->reset('error', 'message');
        try {
            $service->pay(auth()->user(), $this->invoice, $this->payment);
        } catch (PurchaseOrderException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->invoice->refresh();
        $this->message = 'Règlement enregistré.';
        $this->resetPayment();
    }

    public function cancel(PurchaseInvoiceService $service): void
    {
        $this->reset('error', 'message');
        try {
            $service->cancel(auth()->user(), $this->invoice);
            $this->invoice->refresh();
            $this->message = 'Facture annulée : ses réceptions sont de nouveau facturables.';
        } catch (PurchaseOrderException $e) {
            $this->error = $e->getMessage();
        }
    }

    private function resetPayment(): void
    {
        $this->payment = [
            'amount' => number_format((float) $this->invoice->total_amount - (float) $this->invoice->paid_amount, 2, '.', ''),
            'method' => 'transfer',
            'reference' => '',
            'paid_on' => now()->toDateString(),
        ];
    }

    private function selectedValue(PurchaseInvoiceService $service): float
    {
        return $service->uninvoicedReceipts(auth()->user()->pharmacy_id, $this->form['supplier_id'] ?: '0')
            ->whereIn('id', $this->receiptIds)->sum(fn ($r) => $service->receiptValue($r));
    }

    public function render(PurchaseInvoiceService $service)
    {
        $pharmacyId = auth()->user()->pharmacy_id;

        if ($this->invoice) {
            return view('livewire.purchasing.invoice-editor', [
                'reconciliation' => $service->reconcile($this->invoice),
                'payments' => $this->invoice->payments()->latest('paid_on')->get(),
                'methods' => PurchaseInvoiceService::PAYMENT_METHODS,
            ]);
        }

        $receipts = $this->form['supplier_id'] ? $service->uninvoicedReceipts($pharmacyId, $this->form['supplier_id']) : collect();

        return view('livewire.purchasing.invoice-editor', [
            'suppliers' => Supplier::where('pharmacy_id', $pharmacyId)->orderBy('name')->get(),
            'receipts' => $receipts->map(fn ($r) => [$r, $service->receiptValue($r)]),
            'selectedValue' => $receipts->whereIn('id', $this->receiptIds)->sum(fn ($r) => $service->receiptValue($r)),
        ]);
    }
}
