<?php

namespace Tests\Feature;

use App\Livewire\Inventory\StockTransfer;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Presentation;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\StockTransferService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function loc(string $code): string
    {
        return Location::where('code', $code)->value('id');
    }

    private function batch(): Batch
    {
        return Batch::where('lot_number', 'PA2401')->firstOrFail();
    }

    private function stock(string $code): int
    {
        return (int) StockBalance::where('batch_id', $this->batch()->id)->where('location_id', $this->loc($code))->value('quantity');
    }

    public function test_transfer_moves_stock_both_ways_without_changing_total(): void
    {
        $service = app(StockTransferService::class);
        $shelf = $this->stock('RAY');

        $id = $service->transfer($this->user('owner'), $this->batch()->id, $this->loc('RAY'), $this->loc('RES'), 32);
        $this->assertSame($shelf - 32, $this->stock('RAY'));
        $this->assertSame(32, $this->stock('RES'));

        $moves = StockMovement::where('source_type', 'stock_transfer')->where('source_id', $id)->get();
        $this->assertEqualsCanonicalizing(['transfer_out', 'transfer_in'], $moves->pluck('type')->all());
        $this->assertSame(0, (int) $moves->sum('quantity'));

        $service->transfer($this->user('owner'), $this->batch()->id, $this->loc('RES'), $this->loc('RAY'), 32);
        $this->assertSame($shelf, $this->stock('RAY'));
        $this->assertSame(0, $this->stock('RES'));
    }

    public function test_refusals(): void
    {
        $service = app(StockTransferService::class);
        $refused = function (string $reason, callable $fn) {
            try {
                $fn();
                $this->fail("Refus « $reason » attendu.");
            } catch (InventoryException $e) {
                $this->assertSame($reason, $e->reason);
            }
        };

        $refused('forbidden', fn () => $service->transfer($this->user('cashier'), $this->batch()->id, $this->loc('RAY'), $this->loc('RES'), 1));
        $refused('insufficient_stock', fn () => $service->transfer($this->user('owner'), $this->batch()->id, $this->loc('RAY'), $this->loc('RES'), 100000));
        $refused('same_location', fn () => $service->transfer($this->user('owner'), $this->batch()->id, $this->loc('RAY'), $this->loc('RAY'), 1));
        $refused('invalid_location', fn () => $service->transfer($this->user('owner'), $this->batch()->id, $this->loc('RAY'), $this->loc('QUA'), 1));
    }

    public function test_screen_transfers_boxes_after_scan(): void
    {
        $this->actingAs($this->user('owner'));
        $shelf = $this->stock('RAY');
        $sku = 'PARA500-16';

        Livewire::test(StockTransfer::class)
            ->set('fromId', $this->loc('RAY'))->set('toId', $this->loc('RES'))
            ->call('scan', $sku)
            ->assertSet('presentationId', Presentation::where('sku', $sku)->value('id'))
            ->assertSee('PA2401')
            ->set("quantities.{$this->batch()->id}", '2')
            ->call('transfer', $this->batch()->id)
            ->assertSet('error', null)
            ->assertSee('Derniers transferts');

        $this->assertSame($shelf - 32, $this->stock('RAY'));
        $this->assertSame(32, $this->stock('RES'));

        $this->actingAs($this->user('cashier'))->get(route('transfers'))->assertForbidden();
    }
}
