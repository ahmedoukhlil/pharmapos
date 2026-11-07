<?php

namespace Tests\Feature;

use App\Livewire\Pos\Checkout;
use App\Models\Batch;
use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\Presentation;
use App\Models\Register;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Cash\CashSessionException;
use App\Services\Cash\CashSessionService;
use App\Services\Sales\SaleException;
use App\Services\Sales\SaleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ventes en attente : réservation, reprise, annulation, expiration (POS-05, POS-15).
 */
class HeldSaleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private SaleService $service;

    private User $cashier;

    private CashSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SaleService::class);
        $this->cashier = User::where('username', 'cashier')->firstOrFail();
        $register = Register::where('code', 'C01')->firstOrFail();
        $this->session = CashSession::create([
            'pharmacy_id' => $register->pharmacy_id, 'register_id' => $register->id,
            'opened_by' => $this->cashier->id, 'opened_at' => now(), 'opening_float' => 0,
        ]);
    }

    private function item(string $sku, int $qty): array
    {
        return ['presentation_id' => Presentation::where('sku', $sku)->value('id'), 'quantity' => $qty];
    }

    private function balance(string $lot): StockBalance
    {
        return StockBalance::where('batch_id', Batch::where('lot_number', $lot)->value('id'))->firstOrFail();
    }

    private function cash(int $amount): array
    {
        return [['payment_method_id' => PaymentMethod::where('code', 'CASH')->value('id'), 'amount' => $amount]];
    }

    private function finalize(array $items, int $amount, ?string $heldId = null): Sale
    {
        return $this->service->finalize($this->cashier, $this->session, [
            'idempotency_key' => (string) Str::uuid(),
            'items' => $items,
            'payments' => $this->cash($amount),
            'held_sale_id' => $heldId,
        ]);
    }

    public function test_hold_reserves_stock_without_moving_it(): void
    {
        $held = $this->service->hold($this->cashier, $this->session, ['items' => [$this->item('PARA500-16', 2)]]);

        $this->assertSame('on_hold', $held->status);
        $this->assertNull($held->ticket_number);
        $this->assertSame('360.00', $held->total);
        $balance = $this->balance('PA2401');
        $this->assertSame(160, $balance->quantity);
        $this->assertSame(32, $balance->reserved_quantity);
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
    }

    public function test_reserved_stock_is_not_available_to_other_sales(): void
    {
        // AM2403 : 15 boîtes vendables, toutes réservées
        $this->service->hold($this->cashier, $this->session, ['items' => [$this->item('AMOX500-12', 15)]]);

        try {
            $this->finalize([$this->item('AMOX500-12', 1)], 450);
            $this->fail('Stock réservé vendu à un autre client.');
        } catch (SaleException $e) {
            $this->assertSame('insufficient_stock', $e->reason);
        }
    }

    public function test_resumed_sale_is_finalized_on_the_same_record(): void
    {
        $held = $this->service->hold($this->cashier, $this->session, ['items' => [$this->item('AMOX500-12', 15)]]);
        $items = $this->service->heldItems($held);
        $this->assertSame(15, $items[0]['quantity']);

        // Sa propre réservation lui reste disponible
        $sale = $this->finalize($items, 6750, $held->id);

        $this->assertSame($held->id, $sale->id);
        $this->assertSame('finalized', $sale->status);
        $this->assertNotNull($sale->ticket_number);
        $this->assertSame(1, Sale::count());
        $balance = $this->balance('AM2403');
        $this->assertSame(0, $balance->quantity);
        $this->assertSame(0, $balance->reserved_quantity);
        $this->assertSame(0, $sale->reservations()->whereNull('released_at')->count());
    }

    public function test_cancel_releases_reservation(): void
    {
        $held = $this->service->hold($this->cashier, $this->session, ['items' => [$this->item('IBU400-20', 3)]]);
        $this->service->cancelHeld($this->cashier, $held, 'Client parti');

        $this->assertSame('cancelled', $held->fresh()->status);
        $this->assertSame(0, $this->balance('IB2405')->reserved_quantity);

        $this->expectException(SaleException::class);
        $this->finalize($this->service->heldItems($held), 690, $held->id);
    }

    public function test_expired_reservations_are_released_but_sale_stays_on_hold(): void
    {
        $held = $this->service->hold($this->cashier, $this->session, ['items' => [$this->item('IBU400-20', 3)]]);

        $this->travel(31)->minutes();
        $this->artisan('pos:release-expired-reservations')->assertSuccessful();

        $this->assertSame(0, $this->balance('IB2405')->reserved_quantity);
        $this->assertSame('on_hold', $held->fresh()->status);

        // La reprise réaffecte le stock disponible
        $sale = $this->finalize($this->service->heldItems($held), 690, $held->id);
        $this->assertSame('finalized', $sale->status);
        $this->assertSame(340, $this->balance('IB2405')->quantity);
    }

    public function test_cash_session_cannot_close_with_held_sales(): void
    {
        $this->service->hold($this->cashier, $this->session, ['items' => [$this->item('IBU400-20', 1)]]);

        $this->expectException(CashSessionException::class);
        app(CashSessionService::class)->close($this->session, $this->cashier, '0');
    }

    public function test_checkout_screen_hold_and_resume(): void
    {
        $this->actingAs($this->cashier);

        $component = Livewire::test(Checkout::class)
            ->call('addFromSearch', 'IBU400-20')
            ->call('hold')
            ->assertSet('cart', [])
            ->assertSee('Vente mise en attente.');

        $held = Sale::where('status', 'on_hold')->firstOrFail();

        $component->call('addFromSearch', 'CREME-50')
            ->call('resume', $held->id)
            ->assertSee("Mettez d'abord le panier")
            ->call('clear')
            ->call('resume', $held->id)
            ->assertSet('heldSaleId', $held->id)
            ->assertSet('cart.0.presentation_id', Presentation::where('sku', 'IBU400-20')->value('id'))
            ->call('checkout')
            ->assertDispatched('sale-finalized');

        $this->assertSame('finalized', $held->fresh()->status);
        $this->assertSame(1, Sale::count());
    }

    public function test_preparer_sends_sale_to_cashier(): void
    {
        $preparer = User::where('username', 'technician')->firstOrFail();
        // Préparateur sans droit d'encaisser : il ne peut qu'envoyer à la caisse
        \Illuminate\Support\Facades\DB::table('permission_user')->insert(['user_id' => $preparer->id, 'pharmacy_id' => $preparer->pharmacy_id,
            'permission_id' => \App\Models\Permission::firstWhere('slug', 'sale.create')->id, 'granted' => false]);

        $this->actingAs($preparer);
        Livewire::test(Checkout::class)
            ->assertSee('Envoyer à la caisse')
            ->call('addFromSearch', 'IBU400-20')
            ->call('hold')
            ->assertSet('cart', [])
            ->assertSee('Vente envoyée à la caisse');

        $held = Sale::where('status', 'on_hold')->firstOrFail();
        $this->assertTrue($held->user->is($preparer));

        // Côté caissier : la vente apparaît avec le prénom du préparateur, puis s'encaisse
        $this->actingAs($this->cashier);
        Livewire::test(\App\Livewire\Pos\HeldSales::class, ['sessionId' => $this->session->id])
            ->assertSee(Str::of($preparer->name)->before(' '));
        Livewire::test(Checkout::class)
            ->call('resume', $held->id)->assertSet('heldSaleId', $held->id)
            ->call('checkout')->assertDispatched('sale-finalized');
        $this->assertSame('finalized', $held->fresh()->status);
    }
}
