<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Category;
use App\Models\Location;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\Presentation;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Catalogue fictif avec lots couvrant les scénarios de recette :
 * FEFO multi-lots (QA-04), lot expiré (QA-02), lot en quarantaine (QA-03), lots proches de péremption.
 * Codes-barres fictifs (préfixe 200, réservé à l'usage interne GS1).
 */
class CatalogueDemoSeeder extends Seeder
{
    public function run(): void
    {
        $pharmacy = Pharmacy::where('code', 'DEMO01')->firstOrFail();
        $shelf = Location::where('pharmacy_id', $pharmacy->id)->where('code', 'RAY')->firstOrFail();
        $quarantine = Location::where('pharmacy_id', $pharmacy->id)->where('code', 'QUA')->firstOrFail();
        $owner = User::where('username', 'owner')->firstOrFail();

        $supplier = Supplier::create([
            'pharmacy_id' => $pharmacy->id, 'code' => 'GROS01', 'name' => 'Grossiste Démo', 'payment_terms_days' => 30,
        ]);

        $categories = collect(['Antalgiques', 'Antibiotiques', 'Gastro-entérologie', 'Parapharmacie'])
            ->mapWithKeys(fn ($name) => [$name => Category::create([
                'pharmacy_id' => $pharmacy->id, 'name' => $name, 'tax_rate' => 0,
            ])]);

        // [catégorie, type, DCI, marque, dosage, forme, Rx, SKU, libellé, unité, unités/boîte, split, achat, vente, code-barres, lots]
        // lots : [n° lot, mois avant/après péremption, quantité en boîtes, statut]
        $catalogue = [
            ['Antalgiques', 'medicine', 'Paracétamol', 'Paracétamol Démo', '500 mg', 'comprimé', false,
                'PARA500-16', 'Boîte de 16 cp', 'comprimé', 16, true, 120, 180, '2000000000015',
                [['PA2401', 2, 10, 'available'], ['PA2402', 9, 30, 'available'], ['PA2312', -1, 5, 'expired']]],
            ['Antalgiques', 'medicine', 'Ibuprofène', 'Ibuprofène Démo', '400 mg', 'comprimé', false,
                'IBU400-20', 'Boîte de 20 cp', 'comprimé', 20, false, 150, 230, '2000000000022',
                [['IB2405', 14, 20, 'available']]],
            ['Antibiotiques', 'medicine', 'Amoxicilline', 'Amoxicilline Démo', '500 mg', 'gélule', true,
                'AMOX500-12', 'Boîte de 12 gélules', 'gélule', 12, false, 300, 450, '2000000000039',
                [['AM2403', 5, 15, 'available'], ['AM2399', 7, 8, 'quarantine']]],
            ['Gastro-entérologie', 'medicine', 'Oméprazole', 'Oméprazole Démo', '20 mg', 'gélule', false,
                'OME20-14', 'Boîte de 14 gélules', 'gélule', 14, false, 250, 380, '2000000000046',
                [['OM2410', 1, 6, 'available'], ['OM2411', 20, 12, 'available']]],
            ['Parapharmacie', 'parapharmacy', null, 'Crème hydratante Démo', null, 'crème', false,
                'CREME-50', 'Tube 50 ml', 'tube', 1, false, 400, 650, '2000000000053',
                [['CR2401', 24, 10, 'available']]],
        ];

        foreach ($catalogue as [$cat, $type, $inn, $brand, $strength, $form, $rx, $sku, $label, $unit, $perPack, $split, $buy, $sell, $barcode, $lots]) {
            $medicine = Medicine::create([
                'pharmacy_id' => $pharmacy->id, 'category_id' => $categories[$cat]->id, 'type' => $type,
                'inn' => $inn, 'brand_name' => $brand, 'strength' => $strength, 'dosage_form' => $form,
                'prescription_required' => $rx,
            ]);

            $presentation = Presentation::create([
                'pharmacy_id' => $pharmacy->id, 'medicine_id' => $medicine->id, 'sku' => $sku, 'label' => $label,
                'base_unit' => $unit, 'units_per_pack' => $perPack, 'allow_split_sale' => $split,
                'purchase_price' => $buy, 'sale_price' => $sell,
                'unit_sale_price' => $split ? round($sell / $perPack, 4) : null,
                'weighted_avg_cost' => round($buy / $perPack, 4),
                'reorder_threshold' => 5 * $perPack, 'reorder_quantity' => 20 * $perPack,
            ]);

            $presentation->barcodes()->create([
                'pharmacy_id' => $pharmacy->id, 'barcode' => $barcode, 'symbology' => 'ean13',
            ]);

            foreach ($lots as [$lot, $months, $packs, $status]) {
                $expiry = Carbon::today()->addMonthsNoOverflow($months)->endOfMonth();
                $batch = Batch::create([
                    'pharmacy_id' => $pharmacy->id, 'presentation_id' => $presentation->id, 'supplier_id' => $supplier->id,
                    'lot_number' => $lot, 'expiry_raw' => $expiry->format('m/Y'), 'expiry_date' => $expiry,
                    'unit_cost' => round($buy / $perPack, 4), 'received_on' => Carbon::today()->subMonths(2),
                    'status' => $status, 'is_initial_import' => true,
                ]);

                $location = $status === 'quarantine' ? $quarantine : $shelf;
                $quantity = $packs * $perPack;

                StockBalance::create([
                    'pharmacy_id' => $pharmacy->id, 'presentation_id' => $presentation->id,
                    'batch_id' => $batch->id, 'location_id' => $location->id, 'quantity' => $quantity,
                ]);

                StockMovement::create([
                    'pharmacy_id' => $pharmacy->id, 'presentation_id' => $presentation->id, 'batch_id' => $batch->id,
                    'location_id' => $location->id, 'type' => 'initial', 'quantity' => $quantity,
                    'balance_after' => $quantity, 'unit_cost' => $batch->unit_cost,
                    'reason' => 'Stock initial de démonstration', 'user_id' => $owner->id, 'occurred_at' => now(),
                ]);
            }
        }
    }
}
