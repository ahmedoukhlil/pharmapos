<?php

namespace Tests\Feature;

use App\Livewire\Reports\ReportIndex;
use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\Pharmacy;
use App\Models\Presentation;
use App\Models\Register;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Reports\ReportService;
use App\Services\Sales\SaleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rapports essentiels (PRD §12) : ventes, stock, péremptions, caisse ; export CSV.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function filters(): array
    {
        return ReportIndex::filters(['from' => now()->toDateString(), 'to' => now()->toDateString()]);
    }

    /** Deux ventes : 2 boîtes de paracétamol (360) en espèces, 1 ibuprofène en Bankily. */
    private function sell(): CashSession
    {
        $register = Register::where('code', 'C01')->first();
        $cashier = $this->user('cashier');
        $session = CashSession::create(['pharmacy_id' => $register->pharmacy_id, 'register_id' => $register->id,
            'opened_by' => $cashier->id, 'opened_at' => now(), 'opening_float' => 1000]);
        $service = app(SaleService::class);

        $service->finalize($cashier, $session, [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['presentation_id' => Presentation::where('sku', 'PARA500-16')->value('id'), 'quantity' => 2]],
            'payments' => [['payment_method_id' => PaymentMethod::where('code', 'CASH')->value('id'), 'amount' => 360]],
        ]);
        $ibu = Presentation::where('sku', 'IBU400-20')->first();
        $service->finalize($cashier, $session, [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['presentation_id' => $ibu->id, 'quantity' => 1]],
            'payments' => [['payment_method_id' => PaymentMethod::where('code', 'BANKILY')->value('id'), 'amount' => (float) $ibu->sale_price, 'reference' => 'BK1']],
        ]);

        return $session;
    }

    public function test_sales_report_totals_basket_and_margin(): void
    {
        $this->sell();
        $ibuPrice = (float) Presentation::where('sku', 'IBU400-20')->value('sale_price');
        [$byPeriod, $byProduct, , $byCashier] = app(ReportService::class)->build('sales', Pharmacy::first()->id, $this->filters());

        $this->assertCount(1, $byPeriod['rows']);
        $this->assertSame(2, $byPeriod['rows'][0]['tickets']);
        $this->assertEquals(360 + $ibuPrice, $byPeriod['rows'][0]['revenue']);
        $this->assertEquals(round((360 + $ibuPrice) / 2, 2), $byPeriod['rows'][0]['average']);

        $para = collect($byProduct['rows'])->firstWhere('sku', 'PARA500-16');
        $this->assertSame('32 comprimé(s)', $para['qty']);
        $this->assertEquals(360 - 32 * 7.5, $para['margin']);   // coût des lots : 7,50 par comprimé
        $this->assertSame('Démo Caissier', $byCashier['rows'][0]['cashier']);
    }

    public function test_stock_cash_and_expiry_reports(): void
    {
        $session = $this->sell();
        $pharmacyId = Pharmacy::first()->id;
        $reports = app(ReportService::class);

        $stock = collect($reports->build('stock', $pharmacyId, $this->filters())[0]['rows'])->keyBy('sku');
        $this->assertSame(160 - 32 + 480, $stock['PARA500-16']['sellable']);
        $this->assertSame(80, $stock['PARA500-16']['blocked']);   // lot PA2312 périmé
        // Valorisation : quantité de chaque lot × son coût d'achat réel (pas de coût moyen)
        $expected = \Illuminate\Support\Facades\DB::table('stock_balances')->join('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->where('stock_balances.presentation_id', Presentation::where('sku', 'PARA500-16')->value('id'))
            ->sum(\Illuminate\Support\Facades\DB::raw('stock_balances.quantity * batches.unit_cost'));
        $this->assertEqualsWithDelta((float) $expected, $stock['PARA500-16']['value'], 0.01);
        $this->assertGreaterThan(0, $stock['PARA500-16']['value']);
        $this->assertArrayNotHasKey('cmup', $stock['PARA500-16']);

        [$sessions, $byMethod] = $reports->build('cash', $pharmacyId, $this->filters());
        $this->assertSame('C01', $sessions['rows'][0]['register']);
        $this->assertCount(2, $byMethod['rows']);   // espèces et Bankily
        $this->assertEquals(360 + (float) Presentation::where('sku', 'IBU400-20')->value('sale_price'), $byMethod['totals']['received']);
        $this->assertSame($session->id, CashSession::first()->id);

        [$expiring] = $reports->build('expiry', $pharmacyId, $this->filters());
        $this->assertSame('Périmé', collect($expiring['rows'])->firstWhere('lot', 'PA2312')['bucket']);
    }

    public function test_dashboard_kpis_series_and_comparison(): void
    {
        $this->sell();
        $ibuPrice = (float) Presentation::where('sku', 'IBU400-20')->value('sale_price');
        $d = app(\App\Services\Reports\DashboardService::class)->build(Pharmacy::first()->id, $this->filters());

        $this->assertEquals(360 + $ibuPrice, $d['kpis']['revenue']['value']);
        $this->assertSame(2, $d['kpis']['tickets']['value']);
        $this->assertNull($d['kpis']['revenue']['change']);   // aucune vente la veille
        $this->assertCount(1, $d['trend']);
        $this->assertSame(2, collect($d['hours'])->sum('tickets'));
        $this->assertSame('Paracétamol Démo 500 mg', collect($d['topProducts'])->sortByDesc('value')->first()['label']);
        $this->assertCount(2, $d['methods']);
        $this->assertGreaterThan(0, $d['stock']['value']);

        $this->actingAs($this->user('owner'));
        Livewire::test(ReportIndex::class)->assertSet('report', 'dashboard')
            ->assertSee("Chiffre d'affaires")->assertSee('Affluence par heure')->assertSee('Stock aujourd');
    }

    public function test_screen_export_and_permissions(): void
    {
        $this->sell();
        $this->actingAs($this->user('owner'));

        Livewire::test(ReportIndex::class)->set('report', 'sales')->assertSee('Ventes par période')->assertSee('Panier moyen')
            ->set('report', 'stock')->assertSee('Stock et valorisation')
            ->set('report', 'cash')->assertSee('Encaissements par mode');

        $response = $this->get(route('reports.export', ['report' => 'sales', 'table' => 1, 'from' => now()->toDateString(), 'to' => now()->toDateString()]));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}Produit;SKU;", $csv);
        $this->assertStringContainsString('PARA500-16', $csv);
        $this->assertSame(1, AuditLog::where('action', 'report.exported')->count());

        $this->get(route('reports.print', ['report' => 'sales', 'from' => now()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->assertSee('Ventes par période')->assertSee('PARA500-16');

        // Le préparateur consulte sans exporter ; le caissier n'a pas accès
        $this->actingAs($this->user('assistant_pharmacist'));
        $this->get(route('reports.index'))->assertOk()->assertDontSee('Exporter CSV');
        $this->get(route('reports.export', ['report' => 'sales']))->assertForbidden();
        $this->actingAs($this->user('cashier'));
        $this->get(route('reports.index'))->assertForbidden();
        $this->get(route('reports.print', ['report' => 'sales']))->assertForbidden();
    }
}
