<?php

namespace Tests\Feature;

use App\Livewire\Catalogue\CatalogueImport;
use App\Models\Medicine;
use App\Models\Presentation;
use App\Models\User;
use App\Support\ProductLookup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MED-05 : aperçu, erreurs par ligne, doublons, import des seules lignes valides.
 */
class CatalogueImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function csv(): UploadedFile
    {
        $lines = [
            "\u{FEFF}type;nom_commercial;dci;dosage;categorie;sku;libelle;unite_base;unites_par_boite;vente_unite;prix_unite;prix_achat;prix_vente;code_barres",
            'medicament;Doliprane;Paracétamol;1000 mg;Antalgiques;DOLI1000-8;Boîte de 8;comprimé;8;oui;20;120;160;3400936000001',
            'medicament;Doliprane;Paracétamol;1000 mg;Antalgiques;DOLI1000-16;Boîte de 16;comprimé;16;non;;"230,50";"300,00";',
            'parapharmacie;Biafine;;;Dermo;BIAF-93;Tube;tube;1;;;300;420;2000000000015',      // code déjà utilisé
            'medicament;Sans DCI;;;;NODCI-1;Boîte;comprimé;10;;;10;20;',                         // DCI manquante
            'medicament;Paracétamol;Paracétamol;500 mg;;PARA500-16;Boîte;comprimé;16;;;100;180;', // SKU existant
            'medicament;Doublon;X;;;doli1000-8;Boîte;comprimé;8;;;1;2;',                         // doublon interne
            ';;;;;;;;;;;;;',
        ];

        return UploadedFile::fake()->createWithContent('catalogue.csv', implode("\n", $lines));
    }

    public function test_preview_then_import_only_valid_lines(): void
    {
        $this->actingAs(User::where('username', 'owner')->first());
        $before = Presentation::count();

        $component = Livewire::test(CatalogueImport::class)
            ->set('file', $this->csv())
            ->assertHasNoErrors()
            ->assertSet('preview.created', 2)
            ->assertSet('preview.skipped', 2)
            ->assertSet('preview.errors', 2)
            ->assertSee('Code déjà attribué')
            ->assertSee('DCI');

        $this->assertSame($before, Presentation::count());   // l'aperçu n'écrit rien

        $component->call('import')->assertSet('result.created', 2)->assertSee('Import terminé');

        $this->assertSame($before + 2, Presentation::count());
        $this->assertSame(1, Medicine::where('brand_name', 'Doliprane')->count());   // 2 présentations, 1 produit
        $doli16 = Presentation::where('sku', 'DOLI1000-16')->first();
        $this->assertSame('300.00', $doli16->sale_price);
        $this->assertSame('230.50', $doli16->purchase_price);
        $doli8 = ProductLookup::find($doli16->pharmacy_id, '3400936000001')->first();
        $this->assertSame('DOLI1000-8', $doli8->sku);
        $this->assertTrue((bool) $doli8->allow_split_sale);
    }

    public function test_missing_columns_and_permission(): void
    {
        $this->actingAs(User::where('username', 'owner')->first());
        Livewire::test(CatalogueImport::class)
            ->set('file', UploadedFile::fake()->createWithContent('x.csv', "nom;prix\nA;1"))
            ->assertHasErrors('file');

        $this->get(route('catalogue.template'))->assertOk()->assertSee('nom_commercial');

        $this->actingAs(User::where('username', 'cashier')->first());
        $this->get(route('catalogue.import'))->assertForbidden();
    }
}
