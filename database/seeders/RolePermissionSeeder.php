<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Matrice des permissions par profil (PRD §4). À valider avec le pharmacien responsable.
 */
class RolePermissionSeeder extends Seeder
{
    /** slug => [libellé, réauthentification requise] */
    public const PERMISSIONS = [
        'catalogue.view' => ['Consulter le catalogue', false],
        'catalogue.manage' => ['Gérer le catalogue', false],
        'price.update' => ['Modifier les prix', true],
        'sale.create' => ['Encaisser une vente', false],
        'sale.hold' => ['Mettre une vente en attente', false],
        'sale.discount' => ['Accorder une remise', true],
        'sale.cancel' => ['Annuler une vente', true],
        'sale.refund' => ['Retour / remboursement', true],
        'sale.fefo_override' => ['Choisir un lot hors FEFO', true],
        'cash.open_close' => ['Ouvrir / clôturer une caisse', false],
        'cash.validate' => ['Valider une clôture de caisse', true],
        'cash.movement' => ['Entrée / sortie de caisse', true],
        'purchase.manage' => ['Gérer commandes et réceptions', false],
        'purchase.invoice' => ['Factures et règlements fournisseurs', false],
        'batch.quarantine' => ['Mettre en quarantaine / rappel', true],
        'batch.destroy' => ['Sortie pour destruction', true],
        'stock.view' => ['Consulter le stock', false],
        'stock.adjust' => ['Ajuster le stock', true],
        'stock.transfer' => ['Transférer du stock', false],
        'inventory.count' => ['Compter un inventaire', false],
        'inventory.approve' => ['Approuver un inventaire', true],
        'scanner.pair' => ['Appairer un smartphone', false],
        'report.view' => ['Consulter les rapports', false],
        'report.export' => ['Exporter les rapports', false],
        'audit.view' => ["Consulter le journal d'audit", false],
        'user.manage' => ['Gérer les utilisateurs et rôles', true],
        'settings.manage' => ['Gérer les paramètres', true],
        'device.manage' => ['Gérer et révoquer les appareils', true],
        'system.maintain' => ['Maintenance technique', true],
    ];

    /** slug => [libellé, MFA, remise max %, permissions ('*' = toutes, '!x' = sauf x)] */
    public const ROLES = [
        'owner' => ['Propriétaire', true, 100, ['*']],
        'pharmacist_in_charge' => ['Pharmacien responsable', true, 20, ['*', '!system.maintain']],
        'assistant_pharmacist' => ['Pharmacien adjoint', false, 10, [
            'catalogue.view', 'sale.create', 'sale.hold', 'sale.discount', 'sale.refund', 'sale.fefo_override',
            'cash.open_close', 'purchase.manage', 'batch.quarantine', 'stock.view', 'inventory.count',
            'scanner.pair', 'report.view',
        ]],
        'technician' => ['Préparateur', false, 0, [
            'catalogue.view', 'sale.create', 'sale.hold', 'cash.open_close', 'stock.view', 'inventory.count', 'scanner.pair',
        ]],
        'cashier' => ['Caissier', false, 0, [
            'catalogue.view', 'sale.create', 'sale.hold', 'cash.open_close', 'scanner.pair',
        ]],
        'storekeeper' => ['Magasinier', false, 0, [
            'catalogue.view', 'purchase.manage', 'stock.view', 'stock.transfer', 'inventory.count', 'scanner.pair',
        ]],
        'accountant' => ['Comptable', false, 0, [
            'catalogue.view', 'stock.view', 'purchase.invoice', 'cash.validate', 'report.view', 'report.export', 'audit.view',
        ]],
        'tech_admin' => ['Administrateur technique', true, 0, ['system.maintain', 'device.manage']],
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->map(fn ($p, $slug) => Permission::updateOrCreate(
            ['slug' => $slug],
            ['name' => $p[0], 'requires_reauth' => $p[1]],
        ));

        foreach (self::ROLES as $slug => [$name, $mfa, $maxDiscount, $grants]) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'requires_mfa' => $mfa, 'max_discount_percent' => $maxDiscount],
            );

            $slugs = in_array('*', $grants)
                ? $permissions->keys()->reject(fn ($s) => in_array("!$s", $grants))
                : collect($grants);

            $role->permissions()->sync($permissions->only($slugs->all())->pluck('id'));
        }
    }
}
