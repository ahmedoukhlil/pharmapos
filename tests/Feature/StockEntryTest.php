<?php

namespace Tests\Feature;

use App\Livewire\Inventory\StockEntry;
use App\Livewire\Scanner\ScannerPanel;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Presentation;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\StockEntryService;
use App\Services\Scanner\ScannerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class StockEntryTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function owner(): User
    {
        return User::where('username', 'owner')->firstOrFail();
    }

    public function test_unknown_barcode_is_linked_by_name_then_stock_inserted(): void
    {
        $this->actingAs($this->owner());
        $p = Presentation::where('sku', 'PARA500-16')->firstOrFail();
        $ray = Location::where('code', 'RAY')->value('id');

        Livewire::test(StockEntry::class)
            ->call('scan', '6111111111111')->assertSet('pendingBarcode', '6111111111111')
            ->set('nameSearch', 'parac')->assertSee($p->sku)
            ->call('choose', $p->id)->assertSet('presentationId', $p->id)->assertSet('error', null)
            ->set('lot', 'ini01')->set('expiry', '06/2029')->set('quantity', '3')->set('locationId', $ray)
            ->call('insert')->assertSet('error', null)->assertSet('presentationId', null)->assertSee('Dernières entrées');

        $this->assertTrue($p->barcodes()->where('barcode', '6111111111111')->exists());
        $batch = Batch::where('lot_number', 'INI01')->firstOrFail();
        $this->assertTrue($batch->is_initial_import);
        $this->assertSame(48, (int) StockBalance::where('batch_id', $batch->id)->where('location_id', $ray)->value('quantity'));
// Sans n° de lot, date tapée sans « / » (clavier numérique du téléphone)        app(StockEntryService::class)->add($this->owner(), $p->id, '', '012029', 4, $ray);        $this->assertSame('2029-01', Batch::where('lot_number', 'SANS-LOT-2029-01')->firstOrFail()->expiry_date->format('Y-m'));

        // Le code est désormais reconnu : le produit est choisi directement
        Livewire::test(StockEntry::class)->call('scan', '6111111111111')->assertSet('presentationId', $p->id);
    }

    public function test_refusals_and_phone_mode(): void
    {
        $service = app(StockEntryService::class);
        $p = Presentation::where('sku', 'PARA500-16')->firstOrFail();
        $ray = Location::where('code', 'RAY')->value('id');
        foreach ([
            ['forbidden', User::where('username', 'cashier')->first(), 'L1', '06/2029', 1],

            ['invalid_expiry', $this->owner(), 'L1', 'xx', 1],
            ['invalid_quantity', $this->owner(), 'L1', '06/2029', 0],
        ] as [$reason, $user, $lot, $exp, $qty]) {
            try {
                $service->add($user, $p->id, $lot, $exp, $qty, $ray);
                $this->fail("Refus $reason attendu");
            } catch (InventoryException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        $this->actingAs(User::where('username', 'cashier')->first())->get(route('stock-entry'))->assertForbidden();

        // Téléphone : le code inconnu est transmis à l'écran
        $owner = $this->owner();
        $scanner = app(ScannerService::class);
        [$session, $token] = $scanner->createPairing($owner, 'stock', $owner->pharmacy_id);
        $scanner->claim($owner, $token, 'Android');
        $session = $scanner->confirm($owner, $session->fresh());
        $ack = $scanner->ingest($owner, $session->fresh(), ['id' => (string) Str::uuid(), 'sequence' => 1, 'barcode' => '6111111111111']);
        $this->assertSame('received', $ack['status']);
        $this->actingAs($owner);
        Livewire::test(ScannerPanel::class, ['mode' => 'stock', 'contextId' => $owner->pharmacy_id])
            ->call('poll')->assertDispatched('remote-barcode', barcode: '6111111111111');
    }
    public function test_whole_entry_done_on_the_phone(): void
    {
        $owner = $this->owner();
        $scanner = app(ScannerService::class);
        [$session, $token] = $scanner->createPairing($owner, 'stock', $owner->pharmacy_id);
        $scanner->claim($owner, $token, 'Android');
        $session = $scanner->confirm($owner, $session->fresh());
        $p = Presentation::where('sku', 'PARA500-16')->firstOrFail();
        $ray = Location::where('code', 'RAY')->value('id');
        $this->actingAs($owner);

        // Code inconnu → recherche par nom → association
        $this->getJson(route('scanner.lookup', $session).'?code=6111111111111')->assertStatus(422)->assertJson(['error' => 'unknown_product']);
        $this->getJson(route('scanner.search', $session).'?q=parac')->assertOk()->assertJsonFragment(['id' => $p->id, 'sku' => 'PARA500-16']);
        $this->getJson(route('scanner.lookup', $session).'?code=PARA500-16')->assertOk()->assertJson(['presentation_id' => $p->id]);   // produit sans code-barres : par son SKU
        $this->postJson(route('scanner.link', $session), ['barcode' => '6111111111111', 'presentation_id' => $p->id])
            ->assertOk()->assertJson(['presentation_id' => $p->id])->assertJsonFragment(['id' => $ray]);

        // Lot, péremption, quantité : inséré directement
        $this->postJson(route('scanner.scan', $session), ['id' => (string) Str::uuid(), 'sequence' => 1, 'barcode' => '6111111111111',
            'payload' => ['lot_number' => 'tel01', 'expiry' => '05/2029', 'quantity_packs' => 2, 'location_id' => $ray, 'pack_cost' => '']])
            ->assertOk()->assertJson(['status' => 'received']);
        $batch = Batch::where('lot_number', 'TEL01')->firstOrFail();
        $this->assertSame(32, (int) StockBalance::where('batch_id', $batch->id)->where('location_id', $ray)->value('quantity'));

        // Saisie refusée : message renvoyé au téléphone
        $this->postJson(route('scanner.scan', $session), ['id' => (string) Str::uuid(), 'sequence' => 2, 'barcode' => '6111111111111',
            'payload' => ['lot_number' => 'tel02', 'expiry' => 'xx', 'quantity_packs' => 1, 'location_id' => $ray]])
            ->assertStatus(422)->assertJson(['error' => 'invalid_expiry']);
    }
}
