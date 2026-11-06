<?php

namespace Tests\Feature;

use App\Livewire\Catalogue\CatalogueIndex;
use App\Livewire\Catalogue\QuickProduct;
use App\Livewire\Purchasing\ReceiptEditor;
use App\Models\Medicine;
use App\Models\Presentation;
use App\Models\User;
use App\Services\Catalogue\CatalogueService;
use App\Services\DeliveryNotes\DeliveryNoteExtractor;
use App\Support\ProductLabelParser;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Création rapide : formulaire en un écran, coefficient de marge, création depuis un bordereau.
 */
class QuickProductTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function owner(): User
    {
        return User::where('username', 'owner')->firstOrFail();
    }

    public function test_designation_parser(): void
    {
        $this->assertSame(
            ['brand_name' => 'Metformine', 'strength' => '850 mg', 'dosage_form' => 'comprimé', 'units_per_pack' => 30, 'label' => 'Boîte de 30', 'base_unit' => 'comprimé'],
            ProductLabelParser::parse('METFORMINE 850 MG B/30 - LOT: LOT030004'));
        $this->assertSame(['Compresses Steriles', '', 10], array_values(array_intersect_key(
            ProductLabelParser::parse('COMPRESSES STERILES B/10'), array_flip(['brand_name', 'strength', 'units_per_pack']))));
    }

    public function test_one_screen_creation_with_margin_suggested_price(): void
    {
        $this->actingAs($this->owner());
        $this->get(route('catalogue.create'))->assertOk()->assertSee('Créer le produit');

        // Médicaments : ≤ 50 × 1,8 · ≤ 200 × 1,5 · au-delà × 1,25 ; parapharmacie × 1,4
        Livewire::test(CatalogueIndex::class)->call('editMargins')
            ->set('margins.medicine.0.coef', '1,25')->call('addTier', 'medicine')->call('addTier', 'medicine')
            ->set('margins.medicine.0', ['up_to' => '50', 'coef' => '1,8'])->set('margins.medicine.1', ['up_to' => '30', 'coef' => '1.5'])
            ->set('margins.parapharmacy.0.coef', '0.5')
            ->call('saveMargins')->assertHasErrors(['margin_medicine'])
            ->set('margins.medicine.1.up_to', '200')->call('saveMargins')->assertHasErrors(['margin_parapharmacy'])
            ->set('margins.parapharmacy.0.coef', '1.4')->call('saveMargins')->assertHasNoErrors()
            ->assertSee('≤ 50 × 1,80 · ≤ 200 × 1,50 · au-delà × 1,25');
        $id = $this->owner()->pharmacy_id;
        $this->assertSame([90.0, 150.0, 375.0], [CatalogueService::suggestedSalePrice($id, 'medicine', 50),
            CatalogueService::suggestedSalePrice($id, 'medicine', 100), CatalogueService::suggestedSalePrice($id, 'medicine', 300)]);

        Livewire::test(QuickProduct::class, ['mode' => 'page'])
            ->set('form.brand_name', 'Cétirizine Teva')->set('form.inn', 'Cétirizine')->set('form.strength', '10 mg')
            ->set('form.label', 'Boîte de 10')->set('form.units_per_pack', 10)
            ->set('form.purchase_price', '101')->assertSet('form.sale_price', '152')      // 101 × 1,5 arrondi au supérieur
            ->set('form.barcode', '6111234567895')
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $p = Presentation::where('label', 'Boîte de 10')->whereHas('medicine', fn ($q) => $q->where('brand_name', 'Cétirizine Teva'))->sole();
        $this->assertSame(['152.00', '101.00', 'CETIRIZINE10-10'], [$p->sale_price, $p->purchase_price, $p->sku]);
        $this->assertTrue($p->barcodes()->where('barcode', '6111234567895')->exists());

        // Prix saisi à la main : n'est plus recalculé ; parapharmacie : coefficient propre
        Livewire::test(QuickProduct::class, ['mode' => 'page'])->set('form.type', 'parapharmacy')
            ->set('form.purchase_price', '100')->assertSet('form.sale_price', '140')
            ->set('form.sale_price', '199')->set('form.purchase_price', '50')->assertSet('form.sale_price', '199')
            ->call('save')->assertHasErrors('form.brand_name');
    }

    public function test_purchase_price_is_optional_and_products_can_be_chained(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(QuickProduct::class, ['mode' => 'page'])
            ->set('form.brand_name', 'Amoxil')->set('form.inn', 'Amoxicilline')->set('form.strength', '250 mg/5 ml')
            ->set('form.dosage_form', 'suspension buvable')->assertSet('form.base_unit', 'flacon')
            ->set('form.units_per_pack', 12)->assertSet('form.label', 'Boîte de 12')
            ->set('form.label', 'Flacon 60 ml')->set('form.units_per_pack', 1)->assertSet('form.label', 'Flacon 60 ml')
            ->set('form.sale_price', '450')->set('newCategory', ' Antibiotiques ')->call('addCategory')->assertSet('newCategory', '')
            ->call('save', true)->assertHasNoErrors()->assertNoRedirect()
            ->assertSet('form.brand_name', '')->assertSee('Produit créé : Amoxil');

        $p = Presentation::whereHas('medicine', fn ($q) => $q->where('brand_name', 'Amoxil'))->sole();
        $this->assertSame(['0.00', '450.00', 'flacon'], [$p->purchase_price, $p->sale_price, $p->base_unit]);
        $this->assertSame('Antibiotiques', $p->medicine->category->name);
    }

    public function test_unknown_delivery_note_line_is_created_and_linked(): void
    {
        $this->app->instance(DeliveryNoteExtractor::class, new class implements DeliveryNoteExtractor
        {
            public function extract(array $files): array
            {
                return ['delivery_note_number' => 'BLF-9', 'date' => '', 'total' => 0, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'model' => 't'],
                    'lines' => [['designation' => 'VITAMINE C 1000 MG B/20', 'code' => '', 'lot' => 'V1', 'expiry' => '', 'quantity' => 20, 'unit_price' => 70, 'line_total' => 1400]]];
            }
        });
        $this->actingAs($this->owner());
        $receipt = Livewire::test(ReceiptEditor::class)->set('noteFiles', [UploadedFile::fake()->image('bl.jpg')])->call('readNote')
            ->assertSee('Créer ce produit');

        $modal = Livewire::test(QuickProduct::class, ['mode' => 'modal'])
            ->call('openWith', ['designation' => 'VITAMINE C 1000 MG B/20', 'purchase_price' => 70], '0')
            ->assertSet('form.brand_name', 'Vitamine C')->assertSet('form.strength', '1000 mg')->assertSet('form.units_per_pack', 20)
            ->assertSet('form.sale_price', (string) CatalogueService::suggestedSalePrice($this->owner()->pharmacy_id, 'medicine', 70))
            ->call('save')->assertHasNoErrors()->assertDispatched('quick-product-created');

        $id = Presentation::whereHas('medicine', fn ($q) => $q->where('brand_name', 'Vitamine C'))->value('id');
        $receipt->dispatch('quick-product-created', presentationId: $id, ref: '0');
        $this->assertSame([$id, 'ok'], [$receipt->get('note')['lines'][0]['presentation_id'], $receipt->get('note')['lines'][0]['status']]);
        $receipt->call('applyNote')->assertSet('note', null);
        $this->assertSame(1, Medicine::where('brand_name', 'Vitamine C')->count());
    }
}
