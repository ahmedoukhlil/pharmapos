<?php

namespace Database\Seeders;

use App\Models\AlertRule;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Pharmacy;
use App\Models\Register;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Officine de démonstration (données fictives) : emplacements, caisses,
 * moyens de paiement déclaratifs, règles par défaut (Annexe A) et un utilisateur par profil.
 */
class PharmacySeeder extends Seeder
{
    // Mot de passe commun aux comptes de démonstration (jamais en production)
    public const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $pharmacy = Pharmacy::create([
            'code' => 'DEMO01',
            'name' => 'Pharmacie de Démonstration',
            'legal_name' => 'Pharmacie de Démonstration SARL',
            'nif' => '00000000',
            'address' => 'Avenue fictive, Tevragh Zeina',
            'city' => 'Nouakchott',
            'phone' => '+222 00 00 00 00',
        ]);

        $store = Location::create(['pharmacy_id' => $pharmacy->id, 'code' => 'OFF', 'name' => 'Officine', 'type' => 'store', 'is_sellable' => false]);
        $shelf = Location::create(['pharmacy_id' => $pharmacy->id, 'parent_id' => $store->id, 'code' => 'RAY', 'name' => 'Rayon comptoir', 'type' => 'shelf']);
        Location::create(['pharmacy_id' => $pharmacy->id, 'parent_id' => $store->id, 'code' => 'RES', 'name' => 'Réserve', 'type' => 'reserve']);
        Location::create(['pharmacy_id' => $pharmacy->id, 'parent_id' => $store->id, 'code' => 'QUA', 'name' => 'Quarantaine', 'type' => 'quarantine', 'is_sellable' => false]);

        foreach (['C01' => 'Caisse 1', 'C02' => 'Caisse 2'] as $code => $name) {
            Register::create(['pharmacy_id' => $pharmacy->id, 'location_id' => $shelf->id, 'code' => $code, 'name' => $name]);
        }

        // [code, libellé, espèces, référence manuelle, actif]
        $methods = [
            ['CASH', 'Espèces', true, false, true],
            ['BANKILY', 'Bankily', false, true, true],
            ['MASRVI', 'Masrvi', false, true, true],
            ['CARD', 'Carte bancaire', false, true, false],
        ];
        foreach ($methods as $i => [$code, $name, $isCash, $ref, $active]) {
            PaymentMethod::create([
                'pharmacy_id' => $pharmacy->id, 'code' => $code, 'name' => $name, 'is_cash' => $isCash,
                'reference_allowed' => $ref, 'is_active' => $active, 'sort_order' => $i,
            ]);
        }

        $settings = [
            'expiry.month_year_rule' => 'end_of_month',
            'expiry.block_margin_days' => 0,
            'stock.allow_negative' => false,
            'stock.valuation_method' => 'cmup',
            'sale.rounding_step' => 1,
            'sale.hold_reservation_minutes' => 30,
            'scanner.pairing_ttl_seconds' => 120,
            'scanner.session_ttl_minutes' => 480,
            'locale.default' => 'fr',
        ];
        foreach ($settings as $key => $value) {
            Setting::create(['pharmacy_id' => $pharmacy->id, 'key' => $key, 'value' => $value]);
        }

        AlertRule::create(['pharmacy_id' => $pharmacy->id, 'type' => 'expiry', 'parameters' => ['days' => [30, 60, 90, 180]]]);
        AlertRule::create(['pharmacy_id' => $pharmacy->id, 'type' => 'low_stock', 'parameters' => ['use_reorder_threshold' => true]]);
        AlertRule::create(['pharmacy_id' => $pharmacy->id, 'type' => 'cash_variance', 'parameters' => ['tolerance' => 0]]);

        foreach (RolePermissionSeeder::ROLES as $slug => [$label]) {
            $user = User::create([
                'pharmacy_id' => $pharmacy->id,
                'name' => "Démo $label",
                'username' => $slug,
                'email' => "$slug@demo.pharmapos.test",
                'password' => self::DEMO_PASSWORD,
            ]);
            $user->roleAssignments()->create([
                'role_id' => Role::where('slug', $slug)->value('id'),
                'pharmacy_id' => $pharmacy->id,
            ]);
        }
    }
}
