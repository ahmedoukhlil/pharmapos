<?php

namespace App\Services\Settings;

use App\Models\AlertRule;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Pharmacy;
use App\Models\Register;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Création d'une officine prête à l'emploi : emplacements, caisses, modes de paiement,
 * règles par défaut (Annexe A) et compte propriétaire. Utilisé par la console d'administration
 * SaaS (TenantService) et par les données de démonstration.
 */
class PharmacySetupService
{
    /** Modes proposés à l'installation : code => [libellé, espèces, référence]. */
    public const PAYMENT_METHODS = [
        'CASH' => ['Espèces', true, false],
        'BANKILY' => ['Bankily', false, true],
        'MASRVI' => ['Masrvi', false, true],
        'SEDAD' => ['Sedad', false, true],
        'CLICK' => ['Click', false, true],
        'CARD' => ['Carte bancaire', false, true],
    ];

    public const DEFAULT_SETTINGS = [
        'expiry.month_year_rule' => 'end_of_month',
        'expiry.block_margin_days' => 0,
        'stock.allow_negative' => false,
        'stock.valuation_method' => 'batch_cost',
        'sale.rounding_step' => 1,
        'sale.hold_reservation_minutes' => 30,
        'scanner.pairing_ttl_seconds' => 120,
        'scanner.session_ttl_minutes' => 480,
        'locale.default' => 'fr',
    ];

    public const INSTALLED_KEY = 'pharmapos.saas_installed';

    /** Installée dès qu'un super-administrateur existe : c'est lui qui crée ensuite les pharmacies. */
    public static function installed(): bool
    {
        if (Cache::get(self::INSTALLED_KEY)) {
            return true;
        }
        $installed = User::where('is_super_admin', true)->exists();
        if ($installed) {
            Cache::forever(self::INSTALLED_KEY, true);
        }

        return $installed;
    }

    /**
     * @param  array  $pharmacy  name, legal_name, nif, address, city, phone
     * @param  array<int, string>  $registers  noms des caisses
     * @param  array<string, bool>  $methods  code => actif (Espèces toujours active)
     */
    public function createPharmacy(array $pharmacy, array $registers, array $methods): Pharmacy
    {
        return DB::transaction(function () use ($pharmacy, $registers, $methods) {
            if (Role::doesntExist()) {
                (new RolePermissionSeeder)->run();
            }

            $p = Pharmacy::create($pharmacy + ['code' => $pharmacy['code'] ?? $this->code($pharmacy['name'])]);

            $store = Location::create(['pharmacy_id' => $p->id, 'code' => 'OFF', 'name' => 'Officine', 'type' => 'store', 'is_sellable' => false]);
            $shelf = Location::create(['pharmacy_id' => $p->id, 'parent_id' => $store->id, 'code' => 'RAY', 'name' => 'Rayon comptoir', 'type' => 'shelf']);
            Location::create(['pharmacy_id' => $p->id, 'parent_id' => $store->id, 'code' => 'RES', 'name' => 'Réserve', 'type' => 'reserve']);
            Location::create(['pharmacy_id' => $p->id, 'parent_id' => $store->id, 'code' => 'QUA', 'name' => 'Quarantaine', 'type' => 'quarantine', 'is_sellable' => false]);

            foreach (array_values($registers) as $i => $name) {
                Register::create(['pharmacy_id' => $p->id, 'location_id' => $shelf->id, 'code' => sprintf('C%02d', $i + 1), 'name' => $name]);
            }

            $order = 0;
            foreach (self::PAYMENT_METHODS as $code => [$name, $isCash, $ref]) {
                if (! array_key_exists($code, $methods)) {
                    continue;
                }
                PaymentMethod::create(['pharmacy_id' => $p->id, 'code' => $code, 'name' => $name, 'is_cash' => $isCash,
                    'reference_allowed' => $ref, 'is_active' => $isCash || $methods[$code], 'sort_order' => $order++]);
            }

            foreach (self::DEFAULT_SETTINGS as $key => $value) {
                Setting::create(['pharmacy_id' => $p->id, 'key' => $key, 'value' => $value]);
            }

            AlertRule::create(['pharmacy_id' => $p->id, 'type' => 'expiry', 'parameters' => ['days' => [30, 60, 90, 180]]]);
            AlertRule::create(['pharmacy_id' => $p->id, 'type' => 'low_stock', 'parameters' => ['use_reorder_threshold' => true]]);
            AlertRule::create(['pharmacy_id' => $p->id, 'type' => 'cash_variance', 'parameters' => ['tolerance' => 0]]);
            AlertRule::create(['pharmacy_id' => $p->id, 'type' => 'backup_failure', 'parameters' => []]);

            return $p;
        });
    }

    public function createUser(Pharmacy $pharmacy, string $roleSlug, array $data): User
    {
        $user = User::create($data + ['pharmacy_id' => $pharmacy->id]);
        $user->roleAssignments()->create(['role_id' => Role::where('slug', $roleSlug)->value('id'), 'pharmacy_id' => $pharmacy->id]);

        return $user;
    }

    private function code(string $name): string
    {
        $base = Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '')->limit(16, '')->value() ?: 'PHARMA';

        return Pharmacy::where('code', $base)->exists() ? $base.random_int(10, 99) : $base;
    }
}
