<?php

namespace Tests\Feature;

use App\Livewire\Pos\Returns;
use App\Models\Batch;
use App\Models\CashSession;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Presentation;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Sales\SaleException;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * QA-12 : retour de médicament → quarantaine ou traitement autorisé ; avoir et remboursement tracés.
 */
class SaleReturnTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private SaleReturnService $returns;

    private CashSession $session;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->returns = app(SaleReturnService::class);
        $register = Register::where('code', 'C01')->firstOrFail();
        $this->session = CashSession::create([
            'pharmacy_id' => $register->pharmacy_id, 'register_id' => $register->id,
            'opened_by' => $this->user('owner')->id, 'opened_at' => now(), 'opening_float' => 1000,
        ]);
        // 2 boîtes de paracétamol (lot PA2401), 360 MRU en espèces
        $this->sale = app(SaleService::class)->finalize($this->user('cashier'), $this->session, [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['presentation_id' => Presentation::where('sku', 'PARA500-16')->value('id'), 'quantity' => 2]],
            'payments' => [['payment_method_id' => $this->cashMethod(), 'amount' => 360]],
        ]);
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function cashMethod(): string
    {
        return PaymentMethod::where('code', 'CASH')->value('id');
    }

    private function allocationId(): string
    {
        return $this->sale->items->first()->batches->first()->id;
    }

    private function process(string $username, int $units, string $disposition = 'quarantine', ?string $key = null): SaleReturn
    {
        return $this->returns->process($this->user($username), $this->session, $this->sale, [
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'payment_method_id' => $this->cashMethod(),
            'reason' => 'Erreur de délivrance',
            'items' => [['sale_item_batch_id' => $this->allocationId(), 'quantity' => $units, 'disposition' => $disposition]],
        ]);
    }

    private function stock(string $locationCode): int
    {
        return (int) StockBalance::where('batch_id', Batch::where('lot_number', 'PA2401')->value('id'))
            ->where('location_id', Location::where('code', $locationCode)->value('id'))
            ->value('quantity');
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

    public function test_return_goes_to_quarantine_by_default(): void
    {
        $return = $this->process('assistant_pharmacist', 16);

        $this->assertMatchesRegularExpression('/^AV-C01-\d{4}-000001$/', $return->credit_note_number);
        $this->assertSame('180.00', $return->total);
        $this->assertSame('quarantine', $return->items->first()->disposition);

        // Le rayon ne récupère rien ; la quarantaine reçoit la boîte
        $this->assertSame(160 - 32, $this->stock('RAY'));
        $this->assertSame(16, $this->stock('QUA'));
        $this->assertSame(1, StockMovement::where('type', 'sale_return')->count());

        // La vente d'origine n'est pas modifiée ; le remboursement est un paiement négatif
        $sale = $this->sale->fresh();
        $this->assertSame('finalized', $sale->status);
        $this->assertSame('360.00', $sale->total);
        $refund = $sale->payments()->where('kind', 'refund')->firstOrFail();
        $this->assertSame('-180.00', $refund->amount);
    }

    public function test_cash_session_summary_includes_refund(): void
    {
        $this->process('assistant_pharmacist', 16);

        $summary = app(CashSessionService::class)->summary($this->session);
        $this->assertSame(18000, $summary['refunds']);
        $this->assertSame(1000_00 + 360_00 - 180_00, $summary['expected_cash']);
    }

    public function test_cashier_cannot_process_returns(): void
    {
        $this->assertRefused('forbidden', fn () => $this->process('cashier', 16));
    }

    public function test_cannot_return_more_than_sold(): void
    {
        $this->process('assistant_pharmacist', 20);
        $this->assertRefused('quantity_exceeds_sold', fn () => $this->process('assistant_pharmacist', 13));
        $this->process('assistant_pharmacist', 12);
        $this->assertSame([$this->allocationId() => 32], $this->returns->returnedQuantities($this->sale));
    }

    public function test_restock_and_destroy_require_pharmacist(): void
    {
        // Le préparateur n'a ni sale.refund ni batch.quarantine dans la matrice de démo
        $this->assertRefused('forbidden', fn () => $this->process('technician', 16, 'restock_approved'));

        $this->process('assistant_pharmacist', 16, 'restock_approved');
        $this->assertSame(128 + 16, $this->stock('RAY'));

        $this->process('assistant_pharmacist', 16, 'destroy');
        $this->assertSame(0, $this->stock('QUA'));
        $this->assertSame(1, StockMovement::where('type', 'destruction')->count());
    }

    public function test_restock_of_non_sellable_batch_is_refused(): void
    {
        Batch::where('lot_number', 'PA2401')->update(['status' => 'recalled']);

        $this->assertRefused('restock_not_allowed', fn () => $this->process('assistant_pharmacist', 16, 'restock_approved'));
    }

    public function test_replay_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $first = $this->process('assistant_pharmacist', 16, 'quarantine', $key);
        $second = $this->process('assistant_pharmacist', 16, 'quarantine', $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SaleReturn::count());
        $this->assertSame(16, $this->stock('QUA'));
    }

    public function test_returns_screen_and_credit_note(): void
    {
        $this->actingAs($this->user('assistant_pharmacist'));

        Livewire::test(Returns::class, ['registerCode' => 'C01'])
            ->set('ticket', $this->sale->ticket_number)
            ->call('findSale')
            ->assertSee('PA2401')
            ->set("quantities.{$this->allocationId()}", '1')   // saisi en boîtes
            ->assertSee('180,00')
            ->set('reason', 'Doublon')
            ->call('submit')
            ->assertDispatched('return-processed');

        $return = SaleReturn::firstOrFail();
        $this->assertSame(16, $return->items->first()->quantity_base_units);
        $this->get(route('returns.show', $return))->assertOk()->assertSee($return->credit_note_number)->assertSee($this->sale->ticket_number);
    }
}
