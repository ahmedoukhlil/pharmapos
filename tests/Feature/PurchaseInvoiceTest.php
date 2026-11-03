<?php

namespace Tests\Feature;

use App\Livewire\Purchasing\InvoiceEditor;
use App\Models\GoodsReceipt;
use App\Models\Presentation;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Purchasing\PurchaseOrderException;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ACH-05 factures et règlements partiels ; ACH-06 rapprochement commande / réception / facture.
 */
class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private PurchaseInvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PurchaseInvoiceService::class);
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    /**
     * Commande 10 IBU à 150 + 4 OME à 300, reçue : 10 IBU facturés 160 (prix changé), 4 OME dont 1 refusé.
     */
    private function receivedOrder(): GoodsReceipt
    {
        $store = $this->user('storekeeper');
        $orders = app(PurchaseOrderService::class);
        $order = $orders->send($store, $orders->save($store, [
            'supplier_id' => Supplier::where('code', 'GROS01')->value('id'),
            'lines' => [
                ['presentation_id' => Presentation::where('sku', 'IBU400-20')->value('id'), 'quantity_packs' => 10, 'unit_price' => 150],
                ['presentation_id' => Presentation::where('sku', 'OME20-14')->value('id'), 'quantity_packs' => 4, 'unit_price' => 300],
            ],
        ]));

        $receipt = $orders->startReceipt($store, $order);
        $lines = $receipt->lines()->with('presentation')->orderBy('line_no')->get();
        $data = [];
        foreach ($lines as $l) {
            $ibu = $l->presentation->sku === 'IBU400-20';
            $base = ['presentation_id' => $l->presentation_id, 'purchase_order_line_id' => $l->purchase_order_line_id,
                'lot_number' => 'L-'.$l->presentation->sku, 'expiry' => '06/2029'];
            if ($ibu) {
                $data[] = $base + ['quantity_packs' => 10, 'unit_price' => '160'];
            } else {
                $data[] = $base + ['quantity_packs' => 3, 'unit_price' => '300'];
                $data[] = $base + ['quantity_packs' => 1, 'unit_price' => '300', 'decision' => 'refused', 'lot_number' => 'L-CASSE'];
            }
        }
        $receipts = app(GoodsReceiptService::class);
        $receipt = $receipts->saveDraft($store, ['supplier_id' => $receipt->supplier_id, 'location_id' => $receipt->location_id, 'lines' => $data], $receipt);

        return $receipts->validate($store, $receipt);
    }

    private function invoice(GoodsReceipt $receipt, string $total = '2500'): PurchaseInvoice
    {
        return $this->service->create($this->user('accountant'), [
            'supplier_id' => $receipt->supplier_id, 'supplier_invoice_number' => 'FA-1001',
            'invoice_date' => now()->toDateString(), 'total_amount' => $total, 'receipt_ids' => [$receipt->id],
        ]);
    }

    public function test_reconciliation_flags_price_and_refusal(): void
    {
        $receipt = $this->receivedOrder();
        $invoice = $this->invoice($receipt);

        $r = $this->service->reconcile($invoice);
        $this->assertEquals(2500, $r['expected']);     // 10 × 160 + 3 × 300
        $this->assertEquals(0, $r['difference']);
        $this->assertFalse($r['matched']);              // écart de prix + ligne refusée
        $ibu = $r['lines']->firstWhere('sku', 'IBU400-20');
        $this->assertStringContainsString('Prix différent', $ibu->anomalies[0]);
        $this->assertSame(['Refusé à la réception'], $r['lines']->firstWhere('invoiceable', 0)->anomalies);
        $this->assertSame(1, $r['pending_packs']);      // 1 oméprazole refusé reste attendu

        // Échéance par défaut = délai fournisseur
        $supplier = Supplier::find($receipt->supplier_id);
        $this->assertSame(now()->addDays($supplier->payment_terms_days)->toDateString(), $invoice->due_date->toDateString());
    }

    public function test_partial_payments_until_paid(): void
    {
        $invoice = $this->invoice($this->receivedOrder());
        $accountant = $this->user('accountant');

        $this->service->pay($accountant, $invoice, ['amount' => '1000', 'method' => 'transfer', 'reference' => 'VIR-1']);
        $this->assertSame('partially_paid', $invoice->fresh()->status);

        foreach ([['amount' => '1600', 'method' => 'cash', 'reason' => 'overpayment'],
            ['amount' => '100', 'method' => 'cheque', 'reason' => 'reference_required']] as $case) {
            try {
                $this->service->pay($accountant, $invoice, $case);
                $this->fail("Refus {$case['reason']} attendu");
            } catch (PurchaseOrderException $e) {
                $this->assertSame($case['reason'], $e->reason);
            }
        }

        $this->service->pay($accountant, $invoice, ['amount' => '1500,00', 'method' => 'cash']);
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('2500.00', $invoice->paid_amount);

        $this->expectException(PurchaseOrderException::class);
        $this->service->cancel($accountant, $invoice);
    }

    public function test_duplicates_and_double_invoicing_are_refused(): void
    {
        $receipt = $this->receivedOrder();
        $invoice = $this->invoice($receipt);

        try {
            $this->invoice($receipt);
            $this->fail('Doublon attendu');
        } catch (PurchaseOrderException $e) {
            $this->assertSame('duplicate_invoice', $e->reason);
        }

        try {
            $this->service->create($this->user('accountant'), ['supplier_id' => $receipt->supplier_id, 'supplier_invoice_number' => 'FA-1002',
                'invoice_date' => now()->toDateString(), 'total_amount' => 10, 'receipt_ids' => [$receipt->id]]);
            $this->fail('Double facturation attendue');
        } catch (PurchaseOrderException $e) {
            $this->assertSame('receipt_already_invoiced', $e->reason);
        }

        // Après annulation, la réception redevient facturable
        $this->service->cancel($this->user('accountant'), $invoice);
        $this->assertCount(1, $this->service->uninvoicedReceipts($receipt->pharmacy_id, $receipt->supplier_id));

        $this->service->create($this->user('accountant'), ['supplier_id' => $receipt->supplier_id, 'supplier_invoice_number' => 'FA-1001-B',
            'invoice_date' => now()->toDateString(), 'total_amount' => 2500, 'receipt_ids' => [$receipt->id]]);

        $this->expectException(PurchaseOrderException::class);
        $this->service->create($this->user('cashier'), []);
    }

    public function test_screens(): void
    {
        $receipt = $this->receivedOrder();
        $this->actingAs($this->user('accountant'));

        Livewire::test(InvoiceEditor::class)
            ->set('form.supplier_id', $receipt->supplier_id)
            ->assertSee($receipt->number)
            ->set('receiptIds', [$receipt->id])
            ->call('useReceiptsTotal')
            ->assertSet('form.total_amount', '2500.00')
            ->set('form.supplier_invoice_number', 'FA-77')
            ->call('create')
            ->assertRedirect();

        $invoice = PurchaseInvoice::where('supplier_invoice_number', 'FA-77')->firstOrFail();
        Livewire::test(InvoiceEditor::class, ['invoice' => $invoice])
            ->assertSee('Rapprochement à vérifier')->assertSee('Prix différent')
            ->set('payment.amount', '500')->set('payment.reference', 'VIR-9')
            ->call('pay')->assertSee('Règlement enregistré');

        $this->get(route('invoices.index'))->assertOk()->assertSee('FA-77')->assertSee('2 000,00');

        $this->actingAs($this->user('cashier'));
        $this->get(route('invoices.index'))->assertForbidden();
    }

    public function test_invoice_created_from_receipt_screen(): void
    {
        $receipt = $this->receivedOrder();
        $receipt->update(['delivery_note_ref' => 'BL-778']);
        $this->actingAs($this->user('owner'));

        $this->get(route('receipts.edit', $receipt))->assertOk()->assertSee('Créer la facture')
            ->assertSee(route('invoices.create', ['reception' => $receipt->id]), false);

        Livewire::withQueryParams(['reception' => $receipt->id])->test(InvoiceEditor::class)
            ->assertSet('form.supplier_id', $receipt->supplier_id)->assertSet('form.supplier_invoice_number', 'BL-778')
            ->assertSet('form.total_amount', '2500.00')->assertSet('receiptIds', [$receipt->id])
            ->call('create')->assertRedirect();

        $invoice = $this->service->invoiceFor($receipt);
        $this->assertSame('2500.00', $invoice->total_amount);
        $this->get(route('receipts.edit', $receipt))->assertSee('Facture BL-778')->assertSee('Enregistrer un paiement');
    }

    public function test_advance_applied_and_statement(): void
    {
        $receipt = $this->receivedOrder();
        $accountant = $this->user('accountant');
        $supplier = $receipt->supplier;

        try {
            $this->service->advance($accountant, $supplier, ['amount' => '100', 'method' => 'transfer']);
            $this->fail('Référence exigée pour un virement');
        } catch (PurchaseOrderException $e) {
            $this->assertStringContainsString('Référence obligatoire', $e->getMessage());
        }
        $this->service->advance($accountant, $supplier, ['amount' => '3000', 'method' => 'cheque', 'reference' => 'CH-1', 'paid_on' => now()->subDay()->toDateString()]);
        $invoice = $this->invoice($receipt);

        $advance = $this->service->unappliedAdvances($supplier->pharmacy_id, $supplier->id)->sole();
        $this->service->applyAdvance($accountant, $invoice, $advance);

        $invoice->refresh();
        $this->assertSame(['paid', '2500.00'], [$invoice->status, $invoice->paid_amount]);
        $this->assertSame('500.00', $this->service->unappliedAdvances($supplier->pharmacy_id, $supplier->id)->sole()->amount);

        $s = $this->service->statement($supplier);
        $this->assertEquals(-500, $s['closing']);       // crédit en faveur de la pharmacie
        $this->assertCount(3, $s['entries']);           // acompte reliquat, facture, imputation

        $this->actingAs($accountant)->get(route('suppliers.statement', $supplier))->assertOk()->assertSee('Relevé de compte fournisseur')->assertSee('Facture FA-1001');
        Livewire::test(\App\Livewire\Purchasing\InvoiceIndex::class)->call('openAdvance', $supplier->id)
            ->set('advance.amount', '50')->set('advance.method', 'cash')->call('saveAdvance')->assertSee('Acompte enregistré');
    }
}
