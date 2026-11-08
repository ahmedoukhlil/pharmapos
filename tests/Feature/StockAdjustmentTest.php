<?php

namespace Tests\Feature;

use App\Livewire\Inventory\StockAdjustment;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\StockAdjustmentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function user(string $u): User
    {
        return User::where('username', $u)->firstOrFail();
    }

    private function batch(): Batch
    {
        return Batch::where('lot_number', 'PA2401')->firstOrFail();
    }

    private function ray(): string
    {
        return Location::where('code', 'RAY')->value('id');
    }

    private function stock(): int
    {
        return (int) StockBalance::where('batch_id', $this->batch()->id)->where('location_id', $this->ray())->value('quantity');
    }

    public function test_breakage_and_entry_error_are_traced(): void
    {
        $service = app(StockAdjustmentService::class);
        $before = $this->stock();

        $m = $service->adjust($this->user('owner'), $this->batch()->id, $this->ray(), 'out', 3, 'breakage', 'flacon tombé');
        $this->assertSame($before - 3, $this->stock());
        $this->assertSame('adjustment', $m->type);
        $this->assertSame(-3, (int) $m->quantity);
        $this->assertStringContainsString('flacon tombé', $m->reason);

        $service->adjust($this->user('owner'), $this->batch()->id, $this->ray(), 'in', 3, 'entry_error');
        $this->assertSame($before, $this->stock());

        $d = $service->adjust($this->user('owner'), $this->batch()->id, $this->ray(), 'out', 1, 'expired');
        $this->assertSame('destruction', $d->type);
        $this->assertSame(3, AuditLog::whereIn('action', ['stock.adjusted', 'stock.destroyed'])->count());
    }

    public function test_refusals(): void
    {
        $service = app(StockAdjustmentService::class);
        $refused = function (string $reason, callable $fn) {
            try {
                $fn();
                $this->fail("Refus « $reason » attendu.");
            } catch (InventoryException $e) {
                $this->assertSame($reason, $e->reason);
            }
        };
        $b = $this->batch()->id;
        $refused('forbidden', fn () => $service->adjust($this->user('cashier'), $b, $this->ray(), 'out', 1, 'breakage'));
        $refused('insufficient_stock', fn () => $service->adjust($this->user('owner'), $b, $this->ray(), 'out', 100000, 'breakage'));
        $refused('invalid_direction', fn () => $service->adjust($this->user('owner'), $b, $this->ray(), 'in', 1, 'theft'));
        $refused('comment_required', fn () => $service->adjust($this->user('owner'), $b, $this->ray(), 'out', 1, 'other', ' '));
        $refused('invalid_quantity', fn () => $service->adjust($this->user('owner'), $b, $this->ray(), 'out', 0, 'breakage'));
    }

    public function test_screen_removes_boxes_after_scan(): void
    {
        $this->actingAs($this->user('owner'))->withFreshReauth();
        $before = $this->stock();
        $key = $this->batch()->id.'|'.$this->ray();

        Livewire::test(StockAdjustment::class)
            ->call('scan', 'PARA500-16')->assertSee('PA2401')
            ->set('target', $key)->set('direction', 'out')->set('reason', 'breakage')->set('quantity', '1')
            ->call('apply')->assertSet('error', null)->assertSee('Dernières corrections');

        $this->assertSame($before - 16, $this->stock());
        $this->assertSame(1, StockMovement::where('source_type', 'stock_adjustment')->count());

        $this->actingAs($this->user('cashier'))->get(route('stock-adjustments'))->assertForbidden();
    }
}
