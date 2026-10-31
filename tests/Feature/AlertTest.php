<?php

namespace Tests\Feature;

use App\Livewire\Alerts\AlertIndex;
use App\Models\Batch;
use App\Models\CashSession;
use App\Models\Location;
use App\Models\Pharmacy;
use App\Models\Presentation;
use App\Models\Register;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Alerts\AlertService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function pharmacyId(): string
    {
        return Pharmacy::where('code', 'DEMO01')->value('id');
    }

    public function test_expiry_tiers(): void
    {
        $ib = Batch::where('lot_number', 'IB2405')->first();
        $ib->update(['expiry_date' => now()->addDays(20)->toDateString()]);

        $rows = app(AlertService::class)->expiring($this->pharmacyId())->keyBy(fn ($r) => $r->batch->lot_number);

        $this->assertSame(0, $rows['PA2312']->bucket);          // périmé
        $this->assertSame(30, $rows['IB2405']->bucket);
        $this->assertSame(20, $rows['IB2405']->days_left);
        $this->assertGreaterThan(0, $rows['IB2405']->value);
    }

    public function test_low_stock_uses_sellable_stock_only(): void
    {
        $ome = Presentation::where('sku', 'OME20-14')->first();
        $service = app(AlertService::class);
        $this->assertFalse($service->lowStock($this->pharmacyId())->contains('id', $ome->id));

        // Tout le stock passe en quarantaine (non vendable)
        StockBalance::where('presentation_id', $ome->id)->update(['location_id' => Location::where('code', 'QUA')->value('id')]);

        $low = $service->lowStock($this->pharmacyId())->firstWhere('id', $ome->id);
        $this->assertNotNull($low);
        $this->assertSame(0, (int) $low->sellable_quantity);
    }

    public function test_cash_variance_and_screen(): void
    {
        $register = Register::where('code', 'C01')->first();
        $cashier = User::where('username', 'cashier')->first();
        CashSession::create(['pharmacy_id' => $register->pharmacy_id, 'register_id' => $register->id, 'opened_by' => $cashier->id,
            'opened_at' => now()->subHours(8), 'opening_float' => 0, 'closed_by' => $cashier->id, 'closed_at' => now(),
            'expected_cash' => 1000, 'counted_cash' => 995, 'difference' => -5, 'status' => 'closed']);

        $this->assertCount(1, app(AlertService::class)->cashVariances($this->pharmacyId()));

        $this->actingAs(User::where('username', 'owner')->first());
        Livewire::test(AlertIndex::class)
            ->assertSee('Périmés')->assertSee('PA2312')
            ->set('tab', 'cash')->assertSee('-5,00');
        $this->get(route('alerts.index'))->assertOk()->assertSee('Alertes');

        $this->actingAs($cashier);
        $this->get(route('alerts.index'))->assertForbidden();
    }
}
