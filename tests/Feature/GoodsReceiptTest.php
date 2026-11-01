<?php

namespace Tests\Feature;

use App\Livewire\Purchasing\ReceiptEditor;
use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Presentation;
use App\Models\PriceHistory;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\ReceiptException;
use App\Support\ExpiryDate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Réception fournisseur : LOT-01, ACH-03, ACH-04, QA-11 (réception partielle sans commande).
 */
class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private GoodsReceiptService $service;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GoodsReceiptService::class);
        $this->storekeeper = User::where('username', 'storekeeper')->firstOrFail();
    }

    private function presentation(string $sku): Presentation
    {
        return Presentation::where('sku', $sku)->firstOrFail();
    }

    private function draft(array $lines): GoodsReceipt
    {
        return $this->service->saveDraft($this->storekeeper, [
            'supplier_id' => Supplier::value('id'),
            'location_id' => Location::where('code', 'RES')->value('id'),
            'delivery_note_ref' => 'BL-778',
            'lines' => $lines,
        ]);
    }

    private function line(string $sku, int $packs, string $price, string $lot, string $expiry, string $decision = 'accepted'): array
    {
        return [
            'presentation_id' => $this->presentation($sku)->id, 'quantity_packs' => $packs, 'unit_price' => $price,
            'lot_number' => $lot, 'expiry' => $expiry, 'decision' => $decision,
        ];
    }

    private function future(int $months): string
    {
        return now()->addMonths($months)->format('m/Y');
    }

    private function assertRefused(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Refus « $reason » attendu.");
        } catch (ReceiptException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_expiry_parsing(): void
    {
        $pharmacyId = Presentation::value('pharmacy_id');

        $this->assertSame('2027-03-31', ExpiryDate::parse('03/2027', $pharmacyId)->toDateString());
        $this->assertSame('2027-03-31', ExpiryDate::parse('3/27', $pharmacyId)->toDateString());
        $this->assertSame('2028-02-29', ExpiryDate::parse('2028-02', $pharmacyId)->toDateString());
        $this->assertSame('2027-03-15', ExpiryDate::parse('15/03/2027', $pharmacyId)->toDateString());
        $this->assertNull(ExpiryDate::parse('13/2027', $pharmacyId));
        $this->assertNull(ExpiryDate::parse('demain', $pharmacyId));
    }

    public function test_validation_creates_batches_stock_and_updates_purchase_price(): void
    {
        $ibu = $this->presentation('IBU400-20');           // 400 cp en stock à 7,50 / cp
        $receipt = $this->draft([$this->line('IBU400-20', 10, '170', 'ib2501', $this->future(18))]);

        $this->assertSame('draft', $receipt->status);
        $this->assertMatchesRegularExpression('/^RC-\d{4}-000001$/', $receipt->number);
        $this->assertSame(0, StockMovement::where('type', 'receipt')->count());

        $receipt = $this->service->validate($this->storekeeper, $receipt);

        $this->assertSame('validated', $receipt->status);
        $batch = Batch::where('lot_number', 'IB2501')->firstOrFail();   // lot normalisé en majuscules
        $this->assertSame('available', $batch->status);
        $this->assertSame('8.5000', $batch->unit_cost);                  // 170 / 20
        $this->assertSame(200, (int) StockBalance::where('batch_id', $batch->id)->where('location_id', Location::where('code', 'RES')->value('id'))->value('quantity'));

        $ibu->refresh();
        $this->assertSame('170.00', $ibu->purchase_price);
        $this->assertTrue(PriceHistory::where('presentation_id', $ibu->id)->where('price_type', 'purchase')->exists());
    }

    public function test_quarantine_and_refused_lines(): void
    {
        $receipt = $this->draft([
            $this->line('OME20-14', 5, '250', 'OMQ1', $this->future(12), 'quarantine'),
            $this->line('CREME-50', 3, '400', '', '', 'refused'),        // refusé : lot non requis
        ]);
        $this->service->validate($this->storekeeper, $receipt);

        $batch = Batch::where('lot_number', 'OMQ1')->firstOrFail();
        $this->assertSame('quarantine', $batch->status);
        $this->assertSame(70, (int) StockBalance::where('batch_id', $batch->id)->where('location_id', Location::where('code', 'QUA')->value('id'))->value('quantity'));
        $this->assertSame(1, $batch->statusHistory()->count());
        $this->assertSame(1, StockMovement::where('type', 'receipt')->count());
    }

    public function test_existing_lot_is_topped_up_and_mismatched_expiry_refused(): void
    {
        $existing = Batch::where('lot_number', 'PA2402')->firstOrFail();

        $receipt = $this->draft([$this->line('PARA500-16', 2, '120', 'PA2402', $existing->expiry_date->format('m/Y'))]);
        $this->service->validate($this->storekeeper, $receipt);
        $this->assertSame(1, Batch::where('lot_number', 'PA2402')->count());

        $other = $this->draft([$this->line('PARA500-16', 2, '120', 'PA2402', $this->future(30))]);
        $this->assertRefused('lot_expiry_mismatch', fn () => $this->service->validate($this->storekeeper, $other));
    }

    public function test_receipt_controls(): void
    {
        $this->assertRefused('lot_required', fn () => $this->service->validate($this->storekeeper,
            $this->draft([$this->line('IBU400-20', 1, '170', '', '')])));

        $this->assertRefused('expired_on_receipt', fn () => $this->service->validate($this->storekeeper,
            $this->draft([$this->line('IBU400-20', 1, '170', 'OLD1', now()->subMonths(2)->format('m/Y'))])));

        // Lot rappelé : pas de réapprovisionnement
        Batch::where('lot_number', 'IB2405')->update(['status' => 'recalled']);
        $ib = Batch::where('lot_number', 'IB2405')->first();
        $this->assertRefused('batch_blocked', fn () => $this->service->validate($this->storekeeper,
            $this->draft([$this->line('IBU400-20', 1, '170', 'IB2405', $ib->expiry_date->format('m/Y'))])));

        $this->assertRefused('invalid_expiry', fn () => $this->draft([$this->line('IBU400-20', 1, '170', 'X', '31/31/2027')]));

        $validated = $this->draft([$this->line('IBU400-20', 1, '170', 'OK1', $this->future(12))]);
        $this->service->validate($this->storekeeper, $validated);
        $this->assertRefused('not_draft', fn () => $this->service->validate($this->storekeeper, $validated));
    }

    public function test_cashier_cannot_receive(): void
    {
        $this->assertRefused('forbidden', fn () => $this->service->saveDraft(User::where('username', 'cashier')->first(), []));
    }

    public function test_receipt_editor_screen(): void
    {
        $this->actingAs($this->storekeeper);

        $component = Livewire::test(ReceiptEditor::class)
            ->call('addFromSearch', '2000000000022')
            ->call('addFromSearch', '2000000000022')          // scan répété → 2 boîtes
            ->assertSet('lines.0.quantity_packs', '2')
            ->set('lines.0.lot_number', 'scr1')
            ->set('lines.0.expiry', $this->future(20))
            ->call('splitLine', 0)                             // second lot du même produit
            ->set('lines.1.lot_number', 'SCR2')
            ->set('lines.1.expiry', $this->future(24))
            ->call('save')
            ->assertSee('Brouillon RC-');

        $receipt = GoodsReceipt::firstOrFail();

        // Reprise du brouillon puis validation
        Livewire::test(ReceiptEditor::class, ['receipt' => $receipt])
            ->assertSet('lines.1.lot_number', 'SCR2')
            ->call('validateReceipt')
            ->assertSee('validée');

        $this->assertSame('validated', $receipt->fresh()->status);
        $this->assertSame(2, Batch::whereIn('lot_number', ['SCR1', 'SCR2'])->count());
        $this->get(route('receipts.index'))->assertOk()->assertSee($receipt->number);
    }
}
