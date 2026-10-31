<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\CashSession;
use App\Models\OutboxEvent;
use App\Models\PaymentMethod;
use App\Models\Presentation;
use App\Models\Register;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Sales\SaleException;
use App\Services\Sales\SaleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Scénarios de recette QA-01 à QA-04 et idempotence (PRD §19), sur le catalogue de démonstration.
 */
class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private SaleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SaleService::class);
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function openSession(User $user, string $register = 'C01'): CashSession
    {
        $register = Register::where('code', $register)->firstOrFail();

        return CashSession::create([
            'pharmacy_id' => $register->pharmacy_id, 'register_id' => $register->id,
            'opened_by' => $user->id, 'opened_at' => now(), 'opening_float' => 1000,
        ]);
    }

    private function presentation(string $sku): Presentation
    {
        return Presentation::where('sku', $sku)->firstOrFail();
    }

    private function batch(string $lot): Batch
    {
        return Batch::where('lot_number', $lot)->firstOrFail();
    }

    private function stock(string $lot): int
    {
        return (int) StockBalance::where('batch_id', $this->batch($lot)->id)->sum('quantity');
    }

    private function method(string $code): string
    {
        return PaymentMethod::where('code', $code)->value('id');
    }

    private function sell(User $user, CashSession $session, array $items, array $payments, ?string $key = null): Sale
    {
        return $this->service->finalize($user, $session, [
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'items' => $items,
            'payments' => $payments,
        ]);
    }

    private function assertRefused(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Refus « $reason » attendu.");
        } catch (SaleException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    // QA-01 + QA-04 : FEFO proposé, vente et stock cohérents
    public function test_valid_sale_uses_fefo_and_updates_stock(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);

        $sale = $this->sell($cashier, $session,
            [['presentation_id' => $this->presentation('PARA500-16')->id, 'quantity' => 2]],
            [['payment_method_id' => $this->method('CASH'), 'amount' => 500]],
        );

        $this->assertSame('finalized', $sale->status);
        $this->assertSame('360.00', $sale->total);
        $this->assertSame('140.00', $sale->change_given);
        $this->assertMatchesRegularExpression('/^C01-\d{4}-000001$/', $sale->ticket_number);

        // PA2312 est expiré : FEFO prend PA2401 (le plus proche vendable)
        $allocated = $sale->items->first()->batches;
        $this->assertCount(1, $allocated);
        $this->assertSame($this->batch('PA2401')->id, $allocated->first()->batch_id);
        $this->assertSame(160 - 32, $this->stock('PA2401'));
        $this->assertSame(80, $this->stock('PA2312'));

        $this->assertSame(1, StockMovement::where('source_id', $sale->id)->count());
        $this->assertSame(1, OutboxEvent::where('aggregate_id', $sale->id)->count());
    }

    // QA-04 : une quantité qui dépasse le premier lot est répartie sur les suivants par péremption
    public function test_allocation_spans_batches_in_expiry_order(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);

        $sale = $this->sell($cashier, $session,
            [['presentation_id' => $this->presentation('PARA500-16')->id, 'quantity' => 12]],
            [['payment_method_id' => $this->method('CASH'), 'amount' => 2160]],
        );

        $lots = $sale->items->first()->batches->map(fn ($b) => [$b->batch->lot_number, $b->quantity_base_units])->all();
        $this->assertSame([['PA2401', 160], ['PA2402', 32]], $lots);
    }

    // QA-02 : lot expiré refusé côté serveur, même demandé explicitement
    public function test_expired_batch_is_refused(): void
    {
        $pharmacist = $this->user('pharmacist_in_charge');
        $session = $this->openSession($pharmacist);

        $this->assertRefused('batch_not_sellable', fn () => $this->sell($pharmacist, $session,
            [['presentation_id' => $this->presentation('PARA500-16')->id, 'quantity' => 1, 'batch_id' => $this->batch('PA2312')->id]],
            [['payment_method_id' => $this->method('CASH'), 'amount' => 180]],
        ));
        $this->assertSame(80, $this->stock('PA2312'));
    }

    // QA-03 : lot en quarantaine refusé
    public function test_quarantined_batch_is_refused(): void
    {
        $pharmacist = $this->user('pharmacist_in_charge');
        $session = $this->openSession($pharmacist);

        $this->assertRefused('batch_not_sellable', fn () => $this->sell($pharmacist, $session,
            [['presentation_id' => $this->presentation('AMOX500-12')->id, 'quantity' => 1, 'batch_id' => $this->batch('AM2399')->id]],
            [['payment_method_id' => $this->method('CASH'), 'amount' => 450]],
        ));
    }

    // Le stock en quarantaine n'est jamais compté comme vendable
    public function test_quarantined_stock_does_not_count_as_available(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);

        // AM2403 = 15 boîtes vendables ; AM2399 (8 boîtes) est en quarantaine
        $this->assertRefused('insufficient_stock', fn () => $this->sell($cashier, $session,
            [['presentation_id' => $this->presentation('AMOX500-12')->id, 'quantity' => 16]],
            [['payment_method_id' => $this->method('CASH'), 'amount' => 7200]],
        ));
        $this->assertSame(180, $this->stock('AM2403'));
    }

    public function test_fefo_override_requires_permission(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);
        $later = $this->batch('PA2402')->id;
        $items = [['presentation_id' => $this->presentation('PARA500-16')->id, 'quantity' => 1, 'batch_id' => $later]];
        $payments = [['payment_method_id' => $this->method('CASH'), 'amount' => 180]];

        $this->assertRefused('fefo_override_forbidden', fn () => $this->sell($cashier, $session, $items, $payments));

        $pharmacist = $this->user('pharmacist_in_charge');
        $sale = $this->sell($pharmacist, $session, $items, $payments);
        $this->assertTrue($sale->items->first()->batches->first()->fefo_overridden);
    }

    // POS-11 / QA-08 : une retransmission ne crée ni seconde vente ni seconde sortie de stock
    public function test_replay_with_same_key_is_idempotent(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);
        $key = (string) Str::uuid();
        $items = [['presentation_id' => $this->presentation('IBU400-20')->id, 'quantity' => 1]];
        $payments = [['payment_method_id' => $this->method('CASH'), 'amount' => 230]];

        $first = $this->sell($cashier, $session, $items, $payments, $key);
        $second = $this->sell($cashier, $session, $items, $payments, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Sale::count());
        $this->assertSame(400 - 20, $this->stock('IB2405'));
        $this->assertSame(1, $second->payments->count());
    }

    public function test_split_payment_and_mobile_overpayment_rules(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);
        $items = [['presentation_id' => $this->presentation('CREME-50')->id, 'quantity' => 1]];

        // Bankily ne peut pas dépasser le total (aucun rendu possible)
        $this->assertRefused('overpayment_non_cash', fn () => $this->sell($cashier, $session, $items,
            [['payment_method_id' => $this->method('BANKILY'), 'amount' => 700, 'reference' => 'TX123']],
        ));

        $sale = $this->sell($cashier, $session, $items, [
            ['payment_method_id' => $this->method('BANKILY'), 'amount' => 400, 'reference' => 'TX124'],
            ['payment_method_id' => $this->method('CASH'), 'amount' => 300],
        ]);
        $this->assertSame('50.00', $sale->change_given);
        $this->assertSame(2, $sale->payments->count());
    }

    public function test_insufficient_payment_rolls_back_everything(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);

        $this->assertRefused('insufficient_payment', fn () => $this->sell($cashier, $session,
            [['presentation_id' => $this->presentation('OME20-14')->id, 'quantity' => 1]],
            [['payment_method_id' => $this->method('CASH'), 'amount' => 100]],
        ));

        $this->assertSame(0, Sale::count());
        $this->assertSame(84, $this->stock('OM2410'));
    }

    public function test_cashier_cannot_discount_and_split_sale_needs_permission(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);

        $this->assertRefused('discount_forbidden', fn () => $this->sell($cashier, $session,
            [['presentation_id' => $this->presentation('OME20-14')->id, 'quantity' => 1, 'discount_amount' => 20]],
            [['payment_method_id' => $this->method('CASH'), 'amount' => 380]],
        ));

        $this->assertRefused('split_not_allowed', fn () => $this->sell($cashier, $session,
            [['presentation_id' => $this->presentation('OME20-14')->id, 'quantity' => 3, 'sale_unit' => 'unit']],
            [['payment_method_id' => $this->method('CASH'), 'amount' => 380]],
        ));

        // Paracétamol autorise la vente au comprimé : 180 / 16 = 11,25 → 3 cp = 33,75 arrondi à 34
        $sale = $this->sell($cashier, $session,
            [['presentation_id' => $this->presentation('PARA500-16')->id, 'quantity' => 3, 'sale_unit' => 'unit']],
            [['payment_method_id' => $this->method('CASH'), 'amount' => 34]],
        );
        $this->assertSame('34.00', $sale->total);
        $this->assertSame('0.25', $sale->rounding_adjustment);
        $this->assertSame(157, $this->stock('PA2401'));
    }

    public function test_ticket_numbers_are_sequential_per_register(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);
        $items = [['presentation_id' => $this->presentation('IBU400-20')->id, 'quantity' => 1]];
        $payments = [['payment_method_id' => $this->method('CASH'), 'amount' => 230]];

        $numbers = collect(range(1, 3))->map(fn () => $this->sell($cashier, $session, $items, $payments)->ticket_number);

        $this->assertSame(['000001', '000002', '000003'], $numbers->map(fn ($n) => substr($n, -6))->all());
    }

    public function test_closed_session_refuses_sales(): void
    {
        $cashier = $this->user('cashier');
        $session = $this->openSession($cashier);
        $session->update(['status' => 'closed', 'closed_at' => now()]);

        $this->assertRefused('cash_session_closed', fn () => $this->sell($cashier, $session,
            [['presentation_id' => $this->presentation('IBU400-20')->id, 'quantity' => 1]],
            [['payment_method_id' => $this->method('CASH'), 'amount' => 230]],
        ));
    }
}
