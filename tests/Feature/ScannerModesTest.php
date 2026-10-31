<?php

namespace Tests\Feature;

use App\Livewire\Inventory\InventorySheet;
use App\Livewire\Purchasing\ReceiptEditor;
use App\Livewire\Scanner\ScannerPanel;
use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\InventorySession;
use App\Models\Location;
use App\Models\Presentation;
use App\Models\ScannerSession;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Scanner\ScannerException;
use App\Services\Scanner\ScannerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Scanner smartphone, modes B (inventaire) et C (réception) : PRD §8, SCN-08, STK-04.
 */
class ScannerModesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private ScannerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ScannerService::class);
    }

    private function user(string $username = 'storekeeper'): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    /** Le PC (gestionnaire de stock) génère le QR, le téléphone (même profil) le lit, le PC confirme. */
    private function pair(string $mode, string $contextId): ScannerSession
    {
        [$session, $token] = $this->service->createPairing($this->user(), $mode, $contextId);
        $this->service->claim($this->user(), $token, 'Android');

        return $this->service->confirm($this->user(), $session->fresh());
    }

    private function inventory(): InventorySession
    {
        return app(InventoryService::class)->open($this->user(), [
            'scope' => 'partial',
            'location_id' => Location::where('code', 'RAY')->value('id'),
            'presentation_ids' => [Presentation::where('sku', 'PARA500-16')->value('id')],
        ]);
    }

    private function send(ScannerSession $session, int $seq, string $code, array $payload): array
    {
        return $this->service->ingest($this->user(), $session->fresh(),
            ['id' => (string) Str::uuid(), 'sequence' => $seq, 'barcode' => $code, 'payload' => $payload]);
    }

    public function test_inventory_counting_from_phone_is_blind_and_applied(): void
    {
        $inventory = $this->inventory();
        $session = $this->pair('inventory', $inventory->id);

        $info = $this->service->lookup($this->user(), $session, '2000000000015');
        $this->assertCount(3, $info['lines']);                       // PA2312, PA2401, PA2402
        $this->assertArrayNotHasKey('theoretical_quantity', $info['lines'][0]);
        $pa2401 = collect($info['lines'])->firstWhere('lot', 'PA2401');

        $ack = $this->send($session, 1, '2000000000015', ['line_id' => $pa2401['line_id'], 'quantity' => 150]);
        $this->assertSame('received', $ack['status']);
        $this->assertSame(150, $inventory->lines()->find($pa2401['line_id'])->counted_quantity);
        $this->assertSame($session->device_id, $inventory->lines()->find($pa2401['line_id'])->device_id);

        // Lot d'un autre produit pour ce code : refusé
        $ib = Batch::where('lot_number', 'IB2405')->first();
        $this->expectException(ScannerException::class);
        $this->send($session, 2, '2000000000015', ['batch_id' => $ib->id, 'quantity' => 1]);
    }

    public function test_inventory_sheet_refreshes_when_phone_counts(): void
    {
        $inventory = $this->inventory();
        $session = $this->pair('inventory', $inventory->id);
        $line = $inventory->lines()->first();

        $this->actingAs($this->user());
        $panel = Livewire::test(ScannerPanel::class, ['mode' => 'inventory', 'contextId' => $inventory->id]);
        $this->send($session, 1, '2000000000015', ['line_id' => $line->id, 'quantity' => 7]);
        $panel->call('poll')->assertDispatched('remote-inventory-count');

        Livewire::test(InventorySheet::class, ['session' => $inventory])->call('refreshCounts')->assertSee('1 / 3');
    }

    public function test_receipt_lines_from_phone_complete_the_draft(): void
    {
        $receipts = app(GoodsReceiptService::class);
        $ibu = Presentation::where('sku', 'IBU400-20')->first();
        $receipt = $receipts->saveDraft($this->user(), [
            'supplier_id' => Supplier::where('code', 'GROS01')->value('id'),
            'location_id' => Location::where('code', 'RES')->value('id'),
            'lines' => [['presentation_id' => $ibu->id, 'quantity_packs' => 10, 'unit_price' => '150']],
        ]);
        $session = $this->pair('receipt', $receipt->id);

        $this->assertSame(10, $this->service->lookup($this->user(), $session, '2000000000022')['expected'][0]['packs']);

        // Contrôles côté serveur (SCN-08)
        foreach ([[[], 'lot_required'], [['lot_number' => 'X1', 'expiry' => '13/2028', 'quantity_packs' => 1], 'invalid_expiry']] as [$payload, $reason]) {
            try {
                $this->send($session, 9, '2000000000022', $payload);
                $this->fail("Refus $reason attendu");
            } catch (ScannerException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }

        $this->send($session, 1, '2000000000022', ['lot_number' => 'ib2611', 'expiry' => '11/2028', 'quantity_packs' => 6]);
        $this->send($session, 2, '2000000000022', ['lot_number' => 'IB2612', 'expiry' => '12/2028', 'quantity_packs' => 4]);
        $this->send($session, 3, '2000000000046', ['lot_number' => 'OM9', 'expiry' => '01/2029', 'quantity_packs' => 2]);

        // PC : la relève complète la ligne pré-remplie puis ajoute les autres, brouillon enregistré
        $this->actingAs($this->user());
        $panel = Livewire::test(ScannerPanel::class, ['mode' => 'receipt', 'contextId' => $receipt->id])->call('poll');
        $editor = Livewire::test(ReceiptEditor::class, ['receipt' => $receipt]);
        foreach ($panel->effects['dispatches'] as $d) {
            $editor->call('addRemoteLine', ...$d['params']);
        }

        $lines = GoodsReceipt::find($receipt->id)->lines()->orderBy('line_no')->get();
        $this->assertSame(['IB2611', 'IB2612', 'OM9'], $lines->pluck('lot_number')->all());
        $this->assertSame([6, 4, 2], $lines->pluck('quantity_packs')->all());
        $this->assertSame('2028-11-30', $lines[0]->expiry_date->toDateString());
    }

    public function test_cashier_cannot_pair_inventory_and_closed_documents_refuse_scanner(): void
    {
        $inventory = $this->inventory();
        try {
            $this->service->createPairing($this->user('cashier'), 'inventory', $inventory->id);
            $this->fail('Caissier autorisé');
        } catch (ScannerException $e) {
            $this->assertSame('forbidden', $e->reason);
        }

        app(InventoryService::class)->cancel($this->user('pharmacist_in_charge'), $inventory);
        $this->expectException(ScannerException::class);
        $this->service->createPairing($this->user(), 'inventory', $inventory->id);
    }
}
