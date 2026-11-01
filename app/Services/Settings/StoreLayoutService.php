<?php

namespace App\Services\Settings;

use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\Location;
use App\Models\Register;
use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caisses et emplacements de stock de l'officine.
 *
 * Rien n'est supprimé (ventes, sessions et mouvements y font référence) : on désactive.
 * Garde-fous : une caisse avec une session ouverte ou un emplacement contenant du stock ne se
 * désactive pas ; il reste toujours une caisse active, un emplacement vendable et une quarantaine.
 */
class StoreLayoutService
{
    public const LOCATION_TYPES = ['shelf' => 'Rayon', 'reserve' => 'Réserve', 'bin' => 'Casier / frigo', 'quarantine' => 'Quarantaine'];

    public function saveRegister(User $actor, array $data, ?Register $register = null): Register
    {
        $this->authorize($actor, $register);
        $name = trim((string) ($data['name'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 50) {
            $errors['name'] = 'Le nom est obligatoire (50 caractères maximum).';
        } elseif (Register::where('pharmacy_id', $actor->pharmacy_id)->where('name', $name)->when($register, fn ($q) => $q->whereKeyNot($register->id))->exists()) {
            $errors['name'] = 'Une caisse porte déjà ce nom.';
        }
        $location = Location::where('pharmacy_id', $actor->pharmacy_id)->where('is_active', true)->where('is_sellable', true)->find($data['location_id'] ?? null);
        if (! $location) {
            $errors['location_id'] = 'Choisissez un emplacement vendable (le stock vendu en caisse en sort).';
        }
        $width = in_array((string) ($data['printer_width'] ?? '80'), ['58', '80'], true) ? (string) $data['printer_width'] : '80';
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $values = ['name' => $name, 'location_id' => $location->id, 'printer_width' => $width];

        return DB::transaction(function () use ($actor, $register, $values) {
            if ($register) {
                $old = $register->only(array_keys($values));
                $register->update($values);
                $this->audit($actor, 'register.updated', 'register', $register->id, $values, $old);

                return $register;
            }
            $register = Register::create($values + ['pharmacy_id' => $actor->pharmacy_id, 'code' => $this->nextRegisterCode($actor->pharmacy_id), 'is_active' => true]);
            $this->audit($actor, 'register.created', 'register', $register->id, $values + ['code' => $register->code]);

            return $register;
        });
    }

    public function setRegisterActive(User $actor, Register $register, bool $active): void
    {
        $this->authorize($actor, $register);
        if (! $active && CashSession::where('register_id', $register->id)->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['register' => "« {$register->name} » a une session de caisse ouverte : clôturez-la avant de désactiver la caisse."]);
        }
        DB::transaction(function () use ($actor, $register, $active) {
            $register->update(['is_active' => $active]);
            if (! Register::where('pharmacy_id', $actor->pharmacy_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['register' => 'Au moins une caisse doit rester active.']);
            }
            $this->audit($actor, $active ? 'register.activated' : 'register.deactivated', 'register', $register->id, ['is_active' => $active]);
        });
    }

    public function saveLocation(User $actor, array $data, ?Location $location = null): Location
    {
        $this->authorize($actor, $location);
        if ($location?->type === 'store') {
            abort(403);
        }
        $name = trim((string) ($data['name'] ?? ''));
        $type = $location?->type === 'quarantine' ? 'quarantine' : (string) ($data['type'] ?? 'shelf');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 60) {
            $errors['name'] = 'Le nom est obligatoire (60 caractères maximum).';
        } elseif (Location::where('pharmacy_id', $actor->pharmacy_id)->where('name', $name)->when($location, fn ($q) => $q->whereKeyNot($location->id))->exists()) {
            $errors['name'] = 'Un emplacement porte déjà ce nom.';
        }
        if (! isset(self::LOCATION_TYPES[$type]) || ($location && $location->type !== 'quarantine' && $type === 'quarantine' && $this->hasStock($location))) {
            $errors['type'] = 'Type invalide (un emplacement contenant du stock ne devient pas une quarantaine).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // La quarantaine n'est jamais vendable
        $values = ['name' => $name, 'type' => $type, 'is_sellable' => $type !== 'quarantine' && (bool) ($data['is_sellable'] ?? true)];

        return DB::transaction(function () use ($actor, $location, $values) {
            if ($location) {
                if (! $values['is_sellable'] && Register::where('location_id', $location->id)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['is_sellable' => 'Une caisse vend depuis cet emplacement : il doit rester vendable.']);
                }
                $old = $location->only(array_keys($values));
                $location->update($values);
                $this->ensureEssentials($actor->pharmacy_id);
                $this->audit($actor, 'location.updated', 'location', $location->id, $values, $old);

                return $location;
            }
            $root = Location::where('pharmacy_id', $actor->pharmacy_id)->whereNull('parent_id')->where('type', 'store')->first();
            $location = Location::create($values + [
                'pharmacy_id' => $actor->pharmacy_id,
                'parent_id' => $root?->id,
                'code' => $this->locationCode($actor->pharmacy_id, $values['name']),
                'is_active' => true,
            ]);
            $this->audit($actor, 'location.created', 'location', $location->id, $values + ['code' => $location->code]);

            return $location;
        });
    }

    public function setLocationActive(User $actor, Location $location, bool $active): void
    {
        $this->authorize($actor, $location);
        if ($location->type === 'store') {
            abort(403);
        }
        if (! $active) {
            if ($this->hasStock($location)) {
                throw ValidationException::withMessages(['location' => "« {$location->name} » contient encore du stock : transférez-le avant de désactiver l'emplacement."]);
            }
            if (Register::where('location_id', $location->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['location' => "Une caisse active vend depuis « {$location->name} » : rattachez-la à un autre emplacement d'abord."]);
            }
        }
        DB::transaction(function () use ($actor, $location, $active) {
            $location->update(['is_active' => $active]);
            $this->ensureEssentials($actor->pharmacy_id);
            $this->audit($actor, $active ? 'location.activated' : 'location.deactivated', 'location', $location->id, ['is_active' => $active]);
        });
    }

    public function hasStock(Location $location): bool
    {
        return StockBalance::where('location_id', $location->id)->where('quantity', '!=', 0)->exists();
    }

    private function ensureEssentials(string $pharmacyId): void
    {
        $active = Location::where('pharmacy_id', $pharmacyId)->where('is_active', true);
        if (! (clone $active)->where('is_sellable', true)->exists()) {
            throw ValidationException::withMessages(['location' => 'Au moins un emplacement vendable doit rester actif.']);
        }
        if (! (clone $active)->where('type', 'quarantine')->exists()) {
            throw ValidationException::withMessages(['location' => 'Une quarantaine active est nécessaire (retours, lots refusés ou rappelés).']);
        }
    }

    private function nextRegisterCode(string $pharmacyId): string
    {
        $n = Register::where('pharmacy_id', $pharmacyId)->count() + 1;
        while (Register::where('pharmacy_id', $pharmacyId)->where('code', sprintf('C%02d', $n))->exists()) {
            $n++;
        }

        return sprintf('C%02d', $n);
    }

    private function locationCode(string $pharmacyId, string $name): string
    {
        $base = \Illuminate\Support\Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '')->limit(6, '')->value() ?: 'EMP';
        $code = $base;
        for ($i = 2; Location::where('pharmacy_id', $pharmacyId)->where('code', $code)->exists(); $i++) {
            $code = $base.$i;
        }

        return $code;
    }

    private function authorize(User $actor, Register|Location|null $model): void
    {
        if (! $actor->hasPermission('settings.manage') || ($model && $model->pharmacy_id !== $actor->pharmacy_id)) {
            abort(403);
        }
    }

    private function audit(User $actor, string $action, string $type, string $id, array $new, array $old = []): void
    {
        AuditLog::create(['pharmacy_id' => $actor->pharmacy_id, 'user_id' => $actor->id, 'action' => $action,
            'auditable_type' => $type, 'auditable_id' => $id, 'old_values' => $old ?: null, 'new_values' => $new ?: null]);
    }
}
