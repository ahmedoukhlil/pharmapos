<?php

namespace Tests\Feature;

use App\Livewire\Inventory\InventorySheet;
use App\Models\Batch;
use App\Models\CashSession;
use App\Models\InventoryLine;
use App\Models\InventorySession;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Presentation;
use App\Models\Register;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SaleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * QA-10 : inventaire multi-lots, écarts exacts par lot ; STK-03 à STK-06.
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InventoryService::class);
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    /** Inventaire partiel du paracétamol au rayon : lots PA2401 (160), PA2402 (480), PA2312 expiré (80). */
    private function openParacetamol(): InventorySession
    {
        return $this->service->open($this->user('storekeeper'), [
            'scope' => 'partial',
            'location_id' => Location::where('code', 'RAY')->value('id'),
            'presentation_ids' => [Presentation::where('sku', 'PARA500-16')->value('id')],
        ]);
    }

    private function line(InventorySession $session, string $lot): InventoryLine
    {
        return $session->lines()->where('batch_id', Batch::where('lot_number', $lot)->value('id'))->firstOrFail();
    }

    private function countLot(InventorySession $session, string $lot, int $qty, string $user = 'storekeeper'): void
    {
        $this->service->count($this->user($user), $session, ['line_id' => $this->line($session, $lot)->id, 'quantity' => $qty]);
    }

    private function stock(string $lot): int
    {
        return (int) StockBalance::where('batch_id', Batch::where('lot_number', $lot)->value('id'))
            ->where('location_id', Location::where('code', 'RAY')->value('id'))->value('quantity');
    }

    private function assertRefused(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Refus « $reason » attendu.");
        } catch (InventoryException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_multi_batch_variances_are_exact_and_sales_during_count_are_kept(): void
    {
        $session = $this->openParacetamol();
        $this->assertSame(3, $session->lines->count());
        $this->assertSame(160, $this->line($session, 'PA2401')->theoretical_quantity);

        // Une vente d'une boîte (16 cp sur PA2401) pendant le comptage
        $register = Register::where('code', 'C01')->first();
        $cash = CashSession::create(['pharmacy_id' => $register->pharmacy_id, 'register_id' => $register->id,
            'opened_by' => $this->user('cashier')->id, 'opened_at' => now(), 'opening_float' => 0]);
        app(SaleService::class)->finalize($this->user('cashier'), $cash, [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['presentation_id' => Presentation::where('sku', 'PARA500-16')->value('id'), 'quantity' => 1]],
            'payments' => [['payment_method_id' => PaymentMethod::where('code', 'CASH')->value('id'), 'amount' => 180]],
        ]);

        // Comptage fait avant la vente : 150 au lieu de 160, 485 au lieu de 480, expiré conforme
        $this->countLot($session, 'PA2401', 150);
        $this->countLot($session, 'PA2402', 485);
        $this->countLot($session, 'PA2312', 80);
        $this->service->submit($this->user('storekeeper'), $session);

        $this->assertSame(-10, $this->line($session, 'PA2401')->variance);
        $this->assertSame(5, $this->line($session, 'PA2402')->variance);
        $this->assertSame(0, $this->line($session, 'PA2312')->variance);
        $this->assertSame('-75.00', $this->line($session, 'PA2401')->variance_value);   // 10 × 7,50

        $this->service->approve($this->user('pharmacist_in_charge'), $session);

        $this->assertSame(160 - 16 - 10, $this->stock('PA2401'));
        $this->assertSame(485, $this->stock('PA2402'));
        $this->assertSame(80, $this->stock('PA2312'));
        $this->assertSame(2, StockMovement::where('type', 'inventory')->count());
        $this->assertSame('approved', $session->fresh()->status);
    }

    public function test_permissions(): void
    {
        $this->assertRefused('forbidden', fn () => $this->service->open($this->user('cashier'), ['scope' => 'full']));

        $session = $this->openParacetamol();
        foreach (['PA2401' => 160, 'PA2402' => 480, 'PA2312' => 80] as $lot => $qty) {
            $this->countLot($session, $lot, $qty);
        }
        $this->service->submit($this->user('storekeeper'), $session);

        $this->assertRefused('forbidden', fn () => $this->service->approve($this->user('storekeeper'), $session));
    }

    public function test_uncounted_lines_require_confirmation(): void
    {
        $session = $this->openParacetamol();
        $this->countLot($session, 'PA2401', 160);

        $this->assertRefused('uncounted_lines', fn () => $this->service->submit($this->user('storekeeper'), $session));

        $this->service->submit($this->user('storekeeper'), $session, uncountedAsZero: true);
        $this->assertSame(-480, $this->line($session, 'PA2402')->variance);
    }

    public function test_recount_overrides_first_count(): void
    {
        $session = $this->openParacetamol();
        $this->countLot($session, 'PA2401', 100);
        $this->countLot($session, 'PA2402', 480);
        $this->countLot($session, 'PA2312', 80);
        $this->service->submit($this->user('storekeeper'), $session);

        $this->service->requestRecount($this->user('pharmacist_in_charge'), $session, 'Écart trop important');
        $this->countLot($session, 'PA2401', 158);
        $this->service->submit($this->user('storekeeper'), $session);

        $line = $this->line($session, 'PA2401');
        $this->assertSame(100, $line->counted_quantity);
        $this->assertSame(158, $line->recount_quantity);
        $this->assertSame(-2, $line->variance);

        $this->service->approve($this->user('pharmacist_in_charge'), $session);
        $this->assertSame(158, $this->stock('PA2401'));
    }

    public function test_batch_found_outside_theoretical_is_added(): void
    {
        $session = $this->openParacetamol();
        // Un lot d'ibuprofène retrouvé au rayon (hors du périmètre initial)
        $ib = Batch::where('lot_number', 'IB2405')->first();
        $this->service->count($this->user('storekeeper'), $session, [
            'batch_id' => $ib->id, 'location_id' => Location::where('code', 'RAY')->value('id'), 'quantity' => 20,
        ]);
        $this->service->submit($this->user('storekeeper'), $session, uncountedAsZero: true);

        $line = $session->lines()->where('batch_id', $ib->id)->first();
        $this->assertSame(0, $line->theoretical_quantity);
        $this->assertSame(20, $line->variance);

        $this->assertRefused('out_of_scope', fn () => $this->service->count($this->user('storekeeper'),
            $this->openParacetamol(), ['batch_id' => $ib->id, 'location_id' => Location::where('code', 'RES')->value('id'), 'quantity' => 1]));
    }

    public function test_sheet_is_blind_for_counters_and_shows_variances_to_approvers(): void
    {
        $session = $this->openParacetamol();

        $this->actingAs($this->user('storekeeper'));
        $sheet = Livewire::test(InventorySheet::class, ['session' => $session])
            ->assertDontSee('Théorique')
            ->call('scan', '2000000000015')
            ->assertSee('PA2401');

        foreach (['PA2401' => 160, 'PA2402' => 470, 'PA2312' => 80] as $lot => $qty) {
            $sheet->call('count', $this->line($session, $lot)->id, $qty);
        }
        $sheet->call('submit')->assertSee('écarts calculés')->assertSee('en attente de validation');

        $this->actingAs($this->user('pharmacist_in_charge'));
        Livewire::test(InventorySheet::class, ['session' => $session->fresh()])
            ->assertSee('Théorique')
            ->assertSee('-10')
            ->call('approve')
            ->assertSee('stock ajusté');

        $this->assertSame(470, $this->stock('PA2402'));
        $this->get(route('inventories.index'))->assertOk()->assertSee($session->number);
    }
}
