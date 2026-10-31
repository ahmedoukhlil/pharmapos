<?php

namespace App\Services\Catalogue;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\Presentation;
use App\Models\PriceHistory;
use App\Models\ProductBarcode;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Barcode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;

/**
 * Référentiel produits (PRD §5) : MED-01 archivage plutôt que suppression, MED-03 codes-barres
 * multiples et uniques parmi les actifs, MED-04 conditionnements, MED-06 historique des prix.
 * Les erreurs de saisie sont des ValidationException (affichées champ par champ par Livewire).
 */
class CatalogueService
{
    public function saveMedicine(User $user, array $data, ?Medicine $medicine = null): Medicine
    {
        $this->authorize($user, 'catalogue.manage');

        $data = Validator::make($data, [
            'type' => ['required', Rule::in(['medicine', 'parapharmacy'])],
            'brand_name' => ['required', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'max:255', 'required_if:type,medicine'],
            'strength' => ['nullable', 'string', 'max:100'],
            'dosage_form' => ['nullable', 'string', 'max:100'],
            'route' => ['nullable', 'string', 'max:50'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('pharmacy_id', $user->pharmacy_id)],
            'regulatory_status' => ['nullable', 'string', 'max:50'],
            'prescription_required' => ['boolean'],
            'storage_conditions' => ['nullable', 'string', 'max:255'],
        ], [], $this->labels())->validate();

        return DB::transaction(function () use ($user, $data, $medicine) {
            if ($medicine) {
                $this->assertOwned($user, $medicine);
                $old = $medicine->only(array_keys($data));
                $medicine->update($data);
                $this->audit($user, 'medicine.updated', 'medicine', $medicine->id, $old, $medicine->only(array_keys($data)));
            } else {
                $medicine = Medicine::create($data + ['pharmacy_id' => $user->pharmacy_id, 'status' => 'active']);
                $this->audit($user, 'medicine.created', 'medicine', $medicine->id, null, $data);
            }

            return $medicine;
        });
    }

    /**
     * Création ou modification d'une présentation. Un changement de prix exige price.update et
     * alimente l'historique ; le conditionnement d'un produit déjà stocké n'est plus modifiable.
     */
    public function savePresentation(User $user, Medicine $medicine, array $data, ?Presentation $presentation = null): Presentation
    {
        $this->authorize($user, 'catalogue.manage');
        $this->assertOwned($user, $medicine);

        $data = Validator::make($data, [
            'sku' => ['required', 'string', 'max:50',
                Rule::unique('presentations', 'sku')->where('pharmacy_id', $user->pharmacy_id)->ignore($presentation?->id)],
            'label' => ['required', 'string', 'max:255'],
            'base_unit' => ['required', 'string', 'max:30'],
            'units_per_pack' => ['required', 'integer', 'min:1', 'max:10000'],
            'allow_split_sale' => ['boolean'],
            'unit_sale_price' => ['nullable', 'numeric', 'min:0', 'required_if:allow_split_sale,true'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'reorder_threshold' => ['nullable', 'integer', 'min:0'],
            'reorder_quantity' => ['nullable', 'integer', 'min:0'],
        ], [], $this->labels())->validate();

        $data['sku'] = strtoupper(trim($data['sku']));
        $data['reorder_threshold'] = (int) ($data['reorder_threshold'] ?? 0);
        $data['reorder_quantity'] = (int) ($data['reorder_quantity'] ?? 0);
        if (! ($data['allow_split_sale'] ?? false)) {
            $data['unit_sale_price'] = null;
        }

        return DB::transaction(function () use ($user, $medicine, $data, $presentation) {
            if (! $presentation) {
                $presentation = Presentation::create($data + [
                    'pharmacy_id' => $user->pharmacy_id,
                    'medicine_id' => $medicine->id,
                    'weighted_avg_cost' => round($data['purchase_price'] / $data['units_per_pack'], 4),
                    'status' => 'active',
                ]);
                $this->audit($user, 'presentation.created', 'presentation', $presentation->id, null, $data);

                return $presentation;
            }

            $presentation = Presentation::whereKey($presentation->id)->lockForUpdate()->firstOrFail();
            if ($presentation->medicine_id !== $medicine->id) {
                abort(404);
            }

            if ((int) $data['units_per_pack'] !== $presentation->units_per_pack
                && StockBalance::where('presentation_id', $presentation->id)->where('quantity', '!=', 0)->exists()) {
                throw ValidationException::withMessages([
                    'units_per_pack' => 'Conditionnement non modifiable : du stock existe (créez une nouvelle présentation).',
                ]);
            }

            $priceFields = ['purchase_price' => 'purchase', 'sale_price' => 'sale', 'unit_sale_price' => 'unit_sale'];
            $changed = array_filter($priceFields, fn ($type, $field) => $this->cents($presentation->$field) !== $this->cents($data[$field] ?? null), ARRAY_FILTER_USE_BOTH);

            if ($changed && ! $user->hasPermission('price.update')) {
                throw ValidationException::withMessages(['sale_price' => "Vous n'êtes pas autorisé à modifier les prix."]);
            }

            foreach ($changed as $field => $type) {
                PriceHistory::create([
                    'pharmacy_id' => $presentation->pharmacy_id,
                    'presentation_id' => $presentation->id,
                    'price_type' => $type,
                    'old_price' => $presentation->$field,
                    'new_price' => $data[$field] ?? 0,
                    'changed_by' => $user->id,
                    'reason' => 'Modification manuelle',
                    'effective_at' => now(),
                ]);
            }

            $old = $presentation->only(array_keys($data));
            $presentation->update($data);
            $this->audit($user, $changed ? 'price.updated' : 'presentation.updated', 'presentation', $presentation->id, $old, $presentation->only(array_keys($data)));

            return $presentation;
        });
    }

    public function addBarcode(User $user, Presentation $presentation, string $code, ?string $symbology = null): ProductBarcode
    {
        $this->authorize($user, 'catalogue.manage');
        $this->assertOwned($user, $presentation);

        $code = trim($code);
        $symbology ??= Barcode::guessSymbology($code);
        if (! Barcode::isValid($code, $symbology)) {
            throw ValidationException::withMessages(['barcode' => "Code-barres invalide pour le format {$symbology} (clé de contrôle ?)."]);
        }

        $existing = ProductBarcode::with('presentation.medicine')->where('pharmacy_id', $user->pharmacy_id)
            ->where('barcode', $code)->where('is_active', true)->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'barcode' => "Code déjà attribué à {$existing->presentation->medicine->brand_name} ({$existing->presentation->sku}).",
            ]);
        }

        $barcode = $presentation->barcodes()->create([
            'pharmacy_id' => $user->pharmacy_id,
            'barcode' => $code,
            'symbology' => $symbology,
            'is_active' => true,
        ]);
        $this->audit($user, 'barcode.added', 'presentation', $presentation->id, null, ['barcode' => $code]);

        return $barcode;
    }

    /** Un code n'est jamais supprimé : il est désactivé et peut être réattribué. */
    public function deactivateBarcode(User $user, ProductBarcode $barcode): void
    {
        $this->authorize($user, 'catalogue.manage');
        $this->assertOwned($user, $barcode);
        $barcode->update(['is_active' => false]);
        $this->audit($user, 'barcode.deactivated', 'presentation', $barcode->presentation_id, ['barcode' => $barcode->barcode], null);
    }

    /**
     * MED-01 : active, suspendu, retiré ou archivé ; jamais de suppression d'un produit utilisé.
     */
    public function setStatus(User $user, Medicine|Presentation $item, string $status): void
    {
        $this->authorize($user, 'catalogue.manage');
        $this->assertOwned($user, $item);
        if (! in_array($status, ['active', 'suspended', 'withdrawn', 'archived'], true)) {
            throw ValidationException::withMessages(['status' => 'Statut invalide.']);
        }

        $old = $item->status;
        $item->update(['status' => $status]);
        $type = $item instanceof Medicine ? 'medicine' : 'presentation';
        $this->audit($user, "$type.status", $type, $item->id, ['status' => $old], ['status' => $status]);
    }

    public function saveCategory(User $user, string $name, ?string $taxRate = '0'): Category
    {
        $this->authorize($user, 'catalogue.manage');

        return Category::firstOrCreate(
            ['pharmacy_id' => $user->pharmacy_id, 'parent_id' => null, 'name' => trim($name)],
            ['tax_rate' => $taxRate ?? 0],
        );
    }

    public function saveSupplier(User $user, array $data, ?Supplier $supplier = null): Supplier
    {
        $this->authorize($user, 'purchase.manage');
        if ($supplier) {
            $this->assertOwned($user, $supplier);
        }

        $data = Validator::make($data, [
            'code' => ['required', 'string', 'max:30',
                Rule::unique('suppliers', 'code')->where('pharmacy_id', $user->pharmacy_id)->ignore($supplier?->id)],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'nif' => ['nullable', 'string', 'max:30'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['boolean'],
        ], [], $this->labels())->validate();
        $data['code'] = strtoupper(trim($data['code']));
        $data['payment_terms_days'] = (int) ($data['payment_terms_days'] ?? 0);

        if ($supplier) {
            $supplier->update($data);

            return $supplier;
        }

        return Supplier::create($data + ['pharmacy_id' => $user->pharmacy_id]);
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->hasPermission($permission)) {
            abort(403, "Vous n'êtes pas autorisé à modifier le catalogue.");
        }
    }

    private function assertOwned(User $user, $model): void
    {
        abort_unless($model->pharmacy_id === $user->pharmacy_id, 404);
    }

    private function audit(User $user, string $action, string $type, string $id, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'pharmacy_id' => $user->pharmacy_id,
            'user_id' => $user->id,
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }

    private function cents($value): ?int
    {
        return $value === null || $value === '' ? null : (int) round((float) $value * 100);
    }

    private function labels(): array
    {
        return [
            'type' => 'type', 'brand_name' => 'nom commercial', 'inn' => 'DCI', 'strength' => 'dosage',
            'dosage_form' => 'forme', 'category_id' => 'catégorie', 'sku' => 'SKU', 'label' => 'libellé',
            'base_unit' => 'unité de base', 'units_per_pack' => 'unités par boîte', 'unit_sale_price' => "prix à l'unité",
            'purchase_price' => "prix d'achat", 'sale_price' => 'prix de vente', 'reorder_threshold' => 'seuil de réapprovisionnement',
            'code' => 'code', 'name' => 'nom', 'email' => 'e-mail', 'payment_terms_days' => 'délai de paiement',
        ];
    }
}
