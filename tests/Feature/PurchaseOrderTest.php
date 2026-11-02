<?php

namespace Tests\Feature;

use App\Livewire\Purchasing\OrderEditor;
use App\Models\GoodsReceipt;
use App\Models\Presentation;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderException;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ACH-02 commandes ; report des quantités reçues (ACH-06, niveau ligne).
 */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private PurchaseOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PurchaseOrderService::class);
    }

    private function user(string $username = 'storekeeper'): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function sentOrder(): PurchaseOrder
    {
        $order = $this->service->save($this->user(), [
            'supplier_id' => Supplier::where('code', 'GROS01')->value('id'),
            'lines' => [
                ['presentation_id' => Presentation::where('sku', 'IBU400-20')->value('id'), 'quantity_packs' => 10, 'unit_price' => '150.50'],
                ['presentation_id' => Presentation::where('sku', 'OME20-14')->value('id'), 'quantity_packs' => 4, 'unit_price' => '300'],
            ],
        ]);

        return $this->service->send($this->user(), $order);
    }

    /** Complète lot et péremption du brouillon puis valide ; $packs remplace les quantités par SKU. */
    private function receive(PurchaseOrder $order, array $packs = [], array $decisions = []): GoodsReceipt
    {
        $receipt = $this->service->startReceipt($this->user(), $order->fresh());
        $lines = $receipt->lines()->with('presentation')->orderBy('line_no')->get()->map(fn ($l) => [
            'presentation_id' => $l->presentation_id,
            'purchase_order_line_id' => $l->purchase_order_line_id,
            'quantity_packs' => $packs[$l->presentation->sku] ?? $l->quantity_packs,
            'unit_price' => (string) $l->unit_price,
            'lot_number' => 'LOT-'.$l->presentation->sku,
            'expiry' => '12/2029',
            'decision' => $decisions[$l->presentation->sku] ?? 'accepted',
        ])->all();

        $receiptService = app(GoodsReceiptService::class);
        $receipt = $receiptService->saveDraft($this->user(), [
            'supplier_id' => $receipt->supplier_id, 'location_id' => $receipt->location_id, 'lines' => $lines,
        ], $receipt);

        return $receiptService->validate($this->user(), $receipt);
    }

    public function test_printable_purchase_order(): void
    {
        $order = $this->sentOrder();
        $this->actingAs($this->user());
        $this->get(route('orders.print', $order))->assertOk()
            ->assertSee('Bon de commande')->assertSee($order->number)->assertSee($order->supplier->name)->assertDontSee('2 705,00');

        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->get(route('orders.print', $order))->assertForbidden();
    }

    public function test_order_lifecycle_with_partial_receipts(): void
    {
        $order = $this->sentOrder();
        $this->assertSame('sent', $order->status);
        $this->assertSame('2705.00', $order->fresh()->total_amount);    // 10 × 150,50 + 4 × 300
        $this->assertStringStartsWith('BC-', $order->number);

        // 1re livraison : 6 ibuprofènes, oméprazoles refusés
        $this->receive($order, ['IBU400-20' => 6], ['OME20-14' => 'refused']);
        $order->refresh();
        $this->assertSame('partially_received', $order->status);
        $this->assertSame(6, $order->lines->firstWhere('quantity_packs', 10)->received_packs);
        $this->assertSame(0, $order->lines->firstWhere('quantity_packs', 4)->received_packs);

        // 2e réception : pré-remplie avec le reliquat (4 + 4)
        $draft = $this->service->startReceipt($this->user(), $order);
        $this->assertEquals([4, 4], $draft->lines()->orderBy('line_no')->pluck('quantity_packs')->all());
        $this->receive($order);

        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame(2, GoodsReceipt::where('purchase_order_id', $order->id)->where('status', 'validated')->count());
    }

    public function test_sent_order_is_locked_and_received_order_cannot_be_cancelled(): void
    {
        $order = $this->sentOrder();
        try {
            $this->service->save($this->user(), ['supplier_id' => $order->supplier_id, 'lines' => [['presentation_id' => $order->lines->first()->presentation_id, 'quantity_packs' => 1, 'unit_price' => 1]]], $order);
            $this->fail('Commande envoyée modifiable');
        } catch (PurchaseOrderException $e) {
            $this->assertSame('not_draft', $e->reason);
        }

        $this->receive($order, ['IBU400-20' => 1]);
        $this->expectException(PurchaseOrderException::class);
        $this->service->cancel($this->user(), $order->fresh());
    }

    public function test_close_remainder_and_permissions(): void
    {
        $order = $this->sentOrder();
        $this->receive($order, ['IBU400-20' => 2, 'OME20-14' => 1]);
        $this->service->closeRemainder($this->user(), $order->fresh());
        $this->assertSame('received', $order->fresh()->status);

        $this->expectException(PurchaseOrderException::class);
        $this->service->save($this->user('cashier'), ['supplier_id' => $order->supplier_id, 'lines' => []]);
    }

    public function test_screen_builds_order_from_low_stock_and_starts_receipt(): void
    {
        $ome = Presentation::where('sku', 'OME20-14')->first();
        $ome->update(['reorder_threshold' => 100000]);

        $this->actingAs($this->user());
        $component = Livewire::test(OrderEditor::class)
            ->call('addFromSearch', '2000000000015')
            ->call('addFromSearch', '2000000000015')
            ->assertSet('lines.0.quantity_packs', '2')
            ->call('suggest')
            ->assertSee('OME20-14')
            ->call('send')
            ->assertHasNoErrors()->assertSee('envoyée');

        $order = PurchaseOrder::latest()->first();
        $this->assertSame('sent', $order->status);
        $this->assertSame(2, $order->lines()->count());

        $component->call('receive')->assertRedirect(route('receipts.edit', GoodsReceipt::where('purchase_order_id', $order->id)->first()));
        $this->get(route('orders.index'))->assertOk()->assertSee($order->number);
    }
}
