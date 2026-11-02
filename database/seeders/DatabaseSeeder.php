<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            PharmacySeeder::class,
            CatalogueDemoSeeder::class,
        ]);

        // Exploitant SaaS de démonstration : se connecte sans code pharmacie
        \App\Models\User::create(['name' => 'Administrateur PharmaPOS', 'username' => 'admin', 'password' => PharmacySeeder::DEMO_PASSWORD,
            'is_super_admin' => true, 'pharmacy_id' => null]);
    }
}
