<?php

namespace Tests\Feature;

use App\Livewire\Pos\Checkout;
use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutScreenTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function cashier(): User
    {
        return User::where('username', 'cashier')->firstOrFail();
    }

    public function test_login_with_username(): void
    {
        $this->post('/connexion', ['username' => 'cashier', 'password' => 'password'])
            ->assertRedirect(route('pos'));
        $this->assertAuthenticatedAs($this->cashier());
    }

    public function test_cashier_opens_session_scans_and_checks_out(): void
    {
        $this->actingAs($this->cashier());

        $component = Livewire::test(Checkout::class)
            ->assertSee('Ouvrir Caisse 1')
            ->set('openingFloat', '1000')
            ->call('openSession')
            ->assertSee('Encaisser');

        $this->assertSame(1, CashSession::where('status', 'open')->count());

        // Deux scans du même code-barres → quantité 2 (SCN-04)
        $component->set('search', '2000000000015')->call('addFromSearch')
            ->set('search', '2000000000015')->call('addFromSearch')
            ->assertSet('cart.0.quantity', 2)
            ->assertSee('PA2401')                       // lot FEFO proposé, pas le lot expiré
            ->call('addPayment', PaymentMethod::where('code', 'CASH')->value('id'))
            ->set('payments.0.amount', '500')
            ->call('checkout')
            ->assertSet('cart', [])
            ->assertDispatched('sale-finalized');

        $sale = Sale::firstOrFail();
        $this->assertSame('360.00', $sale->total);
        $this->assertSame('140.00', $sale->change_given);

        $this->get(route('tickets.show', $sale))->assertOk()->assertSee($sale->ticket_number)->assertSee('PA2401');
    }

    public function test_search_by_inn(): void
    {
        $this->actingAs($this->cashier());

        Livewire::test(Checkout::class)
            ->set('openingFloat', '0')->call('openSession')
            ->set('search', 'amoxi')
            ->assertSee('Amoxicilline Démo');
    }

    public function test_service_errors_are_shown(): void
    {
        $this->actingAs($this->cashier());

        Livewire::test(Checkout::class)
            ->set('openingFloat', '0')->call('openSession')
            ->set('search', 'AMOX500-12')->call('addFromSearch')
            ->set('cart.0.quantity', 16)
            ->call('checkout')
            ->assertSee('Stock vendable insuffisant');

        $this->assertSame(0, Sale::count());
    }
}
