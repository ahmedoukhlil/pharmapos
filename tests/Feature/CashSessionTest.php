<?php

namespace Tests\Feature;

use App\Livewire\Cash\SessionShow;
use App\Livewire\Pos\CloseSession;
use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\Presentation;
use App\Models\Register;
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
 * QA-14 : clôture de caisse avec écart exact et session verrouillée.
 */
class CashSessionTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private CashSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CashSessionService::class);
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function openSession(User $user, string $float = '1000'): CashSession
    {
        $register = Register::where('code', 'C01')->firstOrFail();

        return CashSession::create([
            'pharmacy_id' => $register->pharmacy_id, 'register_id' => $register->id,
            'opened_by' => $user->id, 'opened_at' => now(), 'opening_float' => $float,
        ]);
    }

    private function sell(User $user, CashSession $session, array $payments): void
    {
        app(SaleService::class)->finalize($user, $session, [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['presentation_id' => Presentation::where('sku', 'CREME-50')->value('id'), 'quantity' => 1]], // 650
            'payments' => array_map(fn ($p) => [
                'payment_method_id' => PaymentMethod::where('code', $p[0])->value('id'), 'amount' => $p[1],
            ], $payments),
        ]);
    }

    private function assertRefused(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Refus « $reason » attendu.");
        } catch (CashSessionException|SaleException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_summary_and_close_compute_exact_difference(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);

        $this->sell($cashier, $session, [['CASH', 1000]]);                    // rendu 350
        $this->sell($cashier, $session, [['BANKILY', 400], ['CASH', 250]]);
        $this->service->recordMovement($session, $this->user('pharmacist_in_charge'), 'cash_out', '200', 'Dépôt banque');

        $summary = $this->service->summary($session);
        $this->assertSame(2, $summary['sales_count']);
        $this->assertSame(130000, $summary['sales_total']);
        $this->assertSame(1250_00 - 350_00, $summary['by_method']['CASH']['amount']);
        $this->assertSame(400_00, $summary['by_method']['BANKILY']['amount']);
        // 1000 + 1250 − 350 − 200 = 1700
        $this->assertSame(1700_00, $summary['expected_cash']);

        $closed = $this->service->close($session, $cashier, '1690', 'Pièce manquante');

        $this->assertSame('closed', $closed->status);
        $this->assertSame('1700.00', $closed->expected_cash);
        $this->assertSame('1690.00', $closed->counted_cash);
        $this->assertSame('-10.00', $closed->difference);
        $this->assertSame('900.00', $closed->totals_by_method['CASH']['amount']);
        $this->assertTrue(AuditLog::where('action', 'cash.closed')->exists());
    }

    public function test_closed_session_is_locked(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);
        $this->service->close($session, $cashier, '1000');

        $this->assertRefused('cash_session_closed', fn () => $this->sell($cashier, $session, [['CASH', 650]]));
        $this->assertRefused('session_not_open', fn () => $this->service->close($session, $cashier, '1000'));
        $this->assertRefused('session_not_open', fn () => $this->service->recordMovement($session, $this->user('owner'), 'cash_in', '10', 'x'));
    }

    public function test_validation_rules(): void
    {
        $cashier = $this->user('cashier');
        $owner = $this->user('owner');
        $session = $this->openSession($owner);

        $this->assertRefused('not_closed', fn () => $this->service->validate($session, $owner));

        $this->service->close($session, $owner, '995');

        // Le caissier n'a pas le droit ; celui qui a clôturé avec un écart ne peut pas valider seul
        $this->assertRefused('forbidden', fn () => $this->service->validate($session, $cashier));
        $this->assertRefused('self_validation', fn () => $this->service->validate($session, $owner));

        $validated = $this->service->validate($session, $this->user('accountant'), 'Écart accepté');
        $this->assertSame('validated', $validated->status);
        $this->assertRefused('not_closed', fn () => $this->service->validate($session, $this->user('accountant')));
    }

    public function test_cashier_cannot_record_movements_and_cash_out_is_capped(): void
    {
        $session = $this->openSession($this->user('cashier'), '100');

        $this->assertRefused('forbidden', fn () => $this->service->recordMovement($session, $this->user('cashier'), 'cash_out', '10', 'x'));
        $this->assertRefused('insufficient_cash', fn () => $this->service->recordMovement($session, $this->user('owner'), 'cash_out', '150', 'x'));
        $this->assertRefused('reason_required', fn () => $this->service->recordMovement($session, $this->user('owner'), 'cash_in', '10', ' '));
    }

    public function test_close_and_validate_screens(): void
    {
        $this->withFreshReauth();
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);
        $this->sell($cashier, $session, [['CASH', 650]]);

        $this->actingAs($cashier);
        Livewire::test(CloseSession::class, ['registerCode' => 'C01'])
            ->assertSee('1 650,00')
            ->set('countedCash', '1650')
            ->assertSee('caisse juste')
            ->call('close')
            ->assertRedirect(route('sessions.show', $session));

        $this->actingAs($this->user('accountant'));
        Livewire::test(SessionShow::class, ['session' => $session->fresh()])
            ->assertSee('Valider la clôture')
            ->call('validateSession')
            ->assertSee('Validée');

        $this->assertSame('validated', $session->fresh()->status);
        $this->get(route('sessions.index', ['status' => 'validated']))->assertOk()->assertSee('Caisse 1');
    }
}
