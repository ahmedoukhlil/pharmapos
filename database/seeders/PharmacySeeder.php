<?php

namespace Database\Seeders;

use App\Services\Settings\PharmacySetupService;
use Illuminate\Database\Seeder;

/**
 * Officine de démonstration (données fictives) : emplacements, caisses,
 * moyens de paiement déclaratifs, règles par défaut (Annexe A) et un utilisateur par profil.
 * Une vraie officine s'installe par l'assistant de premier démarrage (/installation).
 */
class PharmacySeeder extends Seeder
{
    // Mot de passe commun aux comptes de démonstration (jamais en production)
    public const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $setup = app(PharmacySetupService::class);
        $pharmacy = $setup->createPharmacy([
            'code' => 'DEMO01',
            'name' => 'Pharmacie de Démonstration',
            'legal_name' => 'Pharmacie de Démonstration SARL',
            'nif' => '00000000',
            'address' => 'Avenue fictive, Tevragh Zeina',
            'city' => 'Nouakchott',
            'phone' => '+222 00 00 00 00',
        ], ['Caisse 1', 'Caisse 2'], ['CASH' => true, 'BANKILY' => true, 'MASRVI' => true, 'CARD' => false]);

        foreach (RolePermissionSeeder::ROLES as $slug => [$label]) {
            $setup->createUser($pharmacy, $slug, [
                'name' => "Démo $label",
                'username' => $slug,
                'email' => "$slug@demo.pharmapos.test",
                'password' => self::DEMO_PASSWORD,
            ]);
        }
    }
}
