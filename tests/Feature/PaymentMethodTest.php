<?php

namespace Tests\Feature;

use App\Livewire\Pos\Checkout;
use App\Livewire\Settings\PaymentMethodIndex;
use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\Presentation;
use App\Models\Register;
use App\Models\User;
use App\Services\Sales\SaleService;
use App\Services\Settings\PaymentMethodService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Paramétrage des modes de paiement : ajout, ordre, désactivation, suppression protégée.
 */
class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function method(string $code): PaymentMethod
    {
        return PaymentMethod::where('code', $code)->firstOrFail();
    }

    public function test_add_reorder_and_deactivate_from_screen(): void
    {
        $this->actingAs($this->user('owner'));
        Livewire::test(PaymentMethodIndex::class)
            ->call('edit')->set('form.name', 'Sedad')->set('form.is_cash', '0')->call('save')->assertHasNoErrors()
            ->assertSee('Sedad');

        $sedad = PaymentMethod::where('name', 'Sedad')->firstOrFail();
        $this->assertSame('SEDAD', $sedad->code);
        $this->assertFalse($sedad->is_cash);
        $this->assertTrue($sedad->reference_allowed);
        $this->assertSame(1, AuditLog::where('action', 'payment_method.created')->count());

        // Sedad est ajouté en dernier ; on le monte d'un cran puis on désactive Masrvi
        Livewire::test(PaymentMethodIndex::class)->call('move', $sedad->id, -1)->call('toggle', $this->method('MASRVI')->id);
        $order = PaymentMethod::orderBy('sort_order')->pluck('code')->all();
        $this->assertSame(['CASH', 'BANKILY', 'MASRVI', 'SEDAD', 'CARD'], $order);
        $this->assertFalse($this->method('MASRVI')->is_active);

        // La caisse ne propose que les modes actifs, dans l'ordre
        $names = Livewire::test(Checkout::class)->instance()->paymentMethods->pluck('name')->all();
        $this->assertSame(['Espèces', 'Bankily', 'Sedad'], $names);
    }

    public function test_used_method_cannot_be_deleted_nor_change_type_and_cash_is_kept(): void
    {
        $owner = $this->user('owner');
        $service = app(PaymentMethodService::class);
        $register = Register::where('code', 'C01')->first();
        $session = CashSession::create(['pharmacy_id' => $register->pharmacy_id, 'register_id' => $register->id,
            'opened_by' => $owner->id, 'opened_at' => now(), 'opening_float' => 1000]);
        app(SaleService::class)->finalize($this->user('cashier'), $session, [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['presentation_id' => Presentation::where('sku', 'PARA500-16')->value('id'), 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->method('BANKILY')->id, 'amount' => 180, 'reference' => 'BK9']],
        ]);

        $this->assertThrows(fn () => $service->delete($owner, $this->method('BANKILY')), ValidationException::class);
        $this->assertThrows(fn () => $service->save($owner, ['name' => 'Bankily', 'is_cash' => true], $this->method('BANKILY')), ValidationException::class);
        $this->assertThrows(fn () => $service->setActive($owner, $this->method('CASH'), false), ValidationException::class);
        $this->assertTrue($this->method('CASH')->fresh()->is_active);

        // Un mode jamais utilisé se supprime ; le caissier n'a pas accès à l'écran
        $service->delete($owner, $this->method('CARD'));
        $this->assertNull(PaymentMethod::where('code', 'CARD')->first());
        $this->actingAs($this->user('cashier'))->get(route('payment-methods.index'))->assertForbidden();
    }
}
