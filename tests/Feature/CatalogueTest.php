<?php

namespace Tests\Feature;

use App\Livewire\Catalogue\CatalogueIndex;
use App\Livewire\Catalogue\ProductEditor;
use App\Livewire\Purchasing\SupplierIndex;
use App\Models\AuditLog;
use App\Models\Medicine;
use App\Models\Presentation;
use App\Models\PriceHistory;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Services\Catalogue\CatalogueService;
use App\Support\Barcode;
use App\Support\ProductLookup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * MED-01 archivage, MED-03 codes-barres uniques parmi les actifs, MED-06 historique des prix.
 */
class CatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private CatalogueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CatalogueService::class);
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function presentationData(array $over = []): array
    {
        return $over + [
            'sku' => 'DOLI1000-8', 'label' => 'Boîte de 8 comprimés', 'base_unit' => 'comprimé', 'units_per_pack' => 8,
            'allow_split_sale' => false, 'purchase_price' => 120, 'sale_price' => 160,
        ];
    }

    private function newProduct(): Presentation
    {
        $owner = $this->user('owner');
        $medicine = $this->service->saveMedicine($owner, ['type' => 'medicine', 'brand_name' => 'Doliprane', 'inn' => 'Paracétamol', 'strength' => '1000 mg']);

        return $this->service->savePresentation($owner, $medicine, $this->presentationData());
    }

    public function test_barcode_check_digit(): void
    {
        $this->assertTrue(Barcode::isValid('2000000000015', 'ean13'));
        $this->assertFalse(Barcode::isValid('2000000000016', 'ean13'));
        $this->assertTrue(Barcode::isValid('96385074', 'ean8'));
        $this->assertSame('ean13', Barcode::guessSymbology('0612345678901'));
    }

    public function test_created_product_is_found_by_barcode(): void
    {
        $p = $this->newProduct();
        $this->service->addBarcode($this->user('owner'), $p, '6111234567895');

        $this->assertSame($p->id, ProductLookup::find($p->pharmacy_id, '6111234567895')->first()?->id);
        $this->assertEquals(15, $p->fresh()->weighted_avg_cost);   // 120 / 8 comprimés
        $this->assertSame(1, AuditLog::where('action', 'presentation.created')->count());
    }

    public function test_active_barcode_is_unique_and_can_be_reassigned_after_deactivation(): void
    {
        $p = $this->newProduct();
        $owner = $this->user('owner');

        try {
            $this->service->addBarcode($owner, $p, '2000000000015');   // déjà au paracétamol 500
            $this->fail('Doublon attendu');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('PARA500-16', $e->errors()['barcode'][0]);
        }

        $this->service->deactivateBarcode($owner, ProductBarcode::where('barcode', '2000000000015')->first());
        $this->service->addBarcode($owner, $p, '2000000000015');
        $this->assertSame(2, ProductBarcode::where('barcode', '2000000000015')->count());
        $this->assertSame($p->id, ProductLookup::find($p->pharmacy_id, '2000000000015')->first()->id);
    }

    public function test_price_change_requires_permission_and_is_historised(): void
    {
        $para = Presentation::where('sku', 'PARA500-16')->first();
        $data = $this->presentationData(['sku' => 'PARA500-16', 'units_per_pack' => $para->units_per_pack,
            'allow_split_sale' => true, 'unit_sale_price' => 12, 'purchase_price' => $para->purchase_price, 'sale_price' => 195]);

        // Un utilisateur avec catalogue.manage mais sans price.update
        $manager = $this->user('cashier');
        $manager->roleAssignments()->first()->role->permissions()->attach(
            \App\Models\Permission::where('slug', 'catalogue.manage')->value('id'));
        $this->assertRefusedPrice(fn () => $this->service->savePresentation($manager->fresh(), $para->medicine, $data, $para));

        $this->service->savePresentation($this->user('owner'), $para->medicine, $data, $para);
        $this->assertSame('195.00', $para->fresh()->sale_price);
        $history = PriceHistory::where('presentation_id', $para->id)->where('price_type', 'sale')->latest('effective_at')->first();
        $this->assertEquals(180, $history->old_price);
        $this->assertEquals(195, $history->new_price);
    }

    private function assertRefusedPrice(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Refus attendu');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sale_price', $e->errors());
        }
    }

    public function test_pack_size_is_locked_when_stock_exists(): void
    {
        $para = Presentation::where('sku', 'PARA500-16')->first();

        $this->expectException(ValidationException::class);
        $this->service->savePresentation($this->user('owner'), $para->medicine, $this->presentationData([
            'sku' => 'PARA500-16', 'units_per_pack' => 20, 'allow_split_sale' => true, 'unit_sale_price' => $para->unit_sale_price,
            'purchase_price' => $para->purchase_price, 'sale_price' => $para->sale_price,
        ]), $para);
    }

    public function test_archived_product_is_not_sellable_and_cashier_cannot_manage(): void
    {
        $p = $this->newProduct();
        $this->service->addBarcode($this->user('owner'), $p, '6111234567895');
        $this->service->setStatus($this->user('owner'), $p, 'archived');
        $this->assertTrue(ProductLookup::find($p->pharmacy_id, '6111234567895')->isEmpty());
        $this->assertDatabaseHas('presentations', ['id' => $p->id]);

        $this->expectException(HttpException::class);
        $this->service->saveMedicine($this->user('cashier'), ['type' => 'parapharmacy', 'brand_name' => 'X']);
    }

    public function test_screens(): void
    {
        $this->actingAs($this->user('owner'));

        Livewire::test(CatalogueIndex::class)->set('search', '2000000000022')->assertSee('IBU400-20')->assertDontSee('PARA500-16');

        $editor = Livewire::test(ProductEditor::class)
            ->set('form.brand_name', 'Biafine')->set('form.type', 'parapharmacy')
            ->call('save')->assertRedirect();
        $medicine = Medicine::where('brand_name', 'Biafine')->firstOrFail();

        Livewire::test(ProductEditor::class, ['medicine' => $medicine])
            ->call('editPresentation')
            ->set('pres.sku', 'biaf-93')->set('pres.label', 'Tube 93 g')->set('pres.base_unit', 'tube')
            ->set('pres.purchase_price', '300')->set('pres.sale_price', '420')
            ->call('savePresentation')->assertHasNoErrors()->assertSee('BIAF-93')
            ->call('addBarcode', Presentation::where('sku', 'BIAF-93')->value('id'), '2000000000015')
            ->assertHasErrors()->assertSee('Code déjà attribué');

        Livewire::test(SupplierIndex::class)->call('edit')
            ->set('form.code', 'camed')->set('form.name', 'CAMED Nouakchott')->call('save')
            ->assertHasNoErrors()->assertSee('CAMED');

        $this->get(route('catalogue.index'))->assertOk();
        $this->actingAs($this->user('cashier'));
        $this->get(route('suppliers.index'))->assertForbidden();
    }
}
