<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Settings\PharmacySetupService;
use App\Services\Settings\UserService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administration SaaS : création des pharmacies clientes, abonnement, suspension et
 * dépannage du compte propriétaire. Réservé aux super-administrateurs (sans officine).
 * Chaque action est tracée dans le journal d'audit de la pharmacie concernée.
 */
class TenantService
{
    public function __construct(private PharmacySetupService $setup, private UserService $users) {}

    /**
     * @param  array  $pharmacy  code (facultatif), name, legal_name, nif, address, city, phone, plan, subscription_ends_at
     * @param  array  $owner  name, username, phone
     * @return array{0: Pharmacy, 1: User, 2: string} la pharmacie, son propriétaire et son mot de passe provisoire
     */
    public function create(User $actor, array $pharmacy, array $registers, array $methods, array $owner): array
    {
        $this->authorize($actor);
        $pharmacy['code'] = filled($pharmacy['code'] ?? null) ? Str::upper(trim($pharmacy['code'])) : null;
        if ($pharmacy['code'] && ! preg_match('/^[A-Z0-9]{3,20}$/', $pharmacy['code'])) {
            throw ValidationException::withMessages(['pharmacy.code' => 'Code de 3 à 20 lettres ou chiffres, sans espace.']);
        }
        if ($pharmacy['code'] && Pharmacy::where('code', $pharmacy['code'])->exists()) {
            throw ValidationException::withMessages(['pharmacy.code' => 'Ce code est déjà utilisé par une autre pharmacie.']);
        }
        if (! $pharmacy['code']) {
            unset($pharmacy['code']);   // généré à partir du nom
        }
        $password = $this->users->temporaryPassword();

        return DB::transaction(function () use ($actor, $pharmacy, $registers, $methods, $owner, $password) {
            $p = $this->setup->createPharmacy($pharmacy + ['is_active' => true], $registers, $methods);
            $user = $this->setup->createUser($p, UserService::OWNER, [
                'name' => trim($owner['name']),
                'username' => Str::lower(trim($owner['username'])),
                'phone' => trim((string) ($owner['phone'] ?? '')) ?: null,
                'password' => $password,
                'must_change_password' => true,
            ]);
            $this->audit($actor, $p, 'pharmacy.created', ['name' => $p->name, 'code' => $p->code, 'owner' => $user->username,
                'plan' => $p->plan, 'subscription_ends_at' => $p->subscription_ends_at?->toDateString()]);

            return [$p, $user, $password];
        });
    }

    public function suspend(User $actor, Pharmacy $pharmacy, string $reason): void
    {
        $this->authorize($actor);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Indiquez le motif de la suspension.']);
        }
        $pharmacy->update(['is_active' => false, 'suspended_reason' => Str::limit($reason, 250)]);
        $this->audit($actor, $pharmacy, 'pharmacy.suspended', ['reason' => $reason]);
    }

    public function reactivate(User $actor, Pharmacy $pharmacy): void
    {
        $this->authorize($actor);
        $pharmacy->update(['is_active' => true, 'suspended_reason' => null]);
        $this->audit($actor, $pharmacy, 'pharmacy.reactivated', []);
    }

    /** Abonnement : formule et date d'échéance (null = sans échéance). */
    public function setSubscription(User $actor, Pharmacy $pharmacy, ?string $plan, ?string $endsAt): void
    {
        $this->authorize($actor);
        $old = ['plan' => $pharmacy->plan, 'subscription_ends_at' => $pharmacy->subscription_ends_at?->toDateString()];
        $pharmacy->update(['plan' => trim((string) $plan) ?: null, 'subscription_ends_at' => $endsAt ?: null]);
        $this->audit($actor, $pharmacy, 'pharmacy.subscription', ['plan' => $pharmacy->plan,
            'subscription_ends_at' => $pharmacy->subscription_ends_at?->toDateString()], $old);
    }

    /** Prolonge à partir de l'échéance, ou d'aujourd'hui si elle est passée ou absente. */
    public function extend(User $actor, Pharmacy $pharmacy, int $months): void
    {
        $from = $pharmacy->subscription_ends_at && ! $pharmacy->subscriptionExpired() ? $pharmacy->subscription_ends_at : today();
        $this->setSubscription($actor, $pharmacy, $pharmacy->plan, $from->copy()->addMonthsNoOverflow($months)->toDateString());
    }

    /** @return array{0: User, 1: string} propriétaire et nouveau mot de passe provisoire */
    public function resetOwnerPassword(User $actor, Pharmacy $pharmacy): array
    {
        $this->authorize($actor);
        $owner = User::where('pharmacy_id', $pharmacy->id)->whereHas('roles', fn ($q) => $q->where('slug', UserService::OWNER))
            ->orderByDesc('is_active')->oldest()->first()
            ?? throw ValidationException::withMessages(['pharmacy' => "Cette pharmacie n'a pas de propriétaire."]);
        $password = $this->users->temporaryPassword();
        $owner->forceFill(['password' => $password, 'must_change_password' => true, 'is_active' => true])->save();
        $this->audit($actor, $pharmacy, 'pharmacy.owner_password_reset', ['user' => $owner->username]);

        return [$owner, $password];
    }

    /** Mot de passe provisoire pour n'importe quel utilisateur d'une pharmacie (compte réactivé). */
    public function resetUserPassword(User $actor, User $user): string
    {
        $this->authorize($actor);
        abort_if($user->is_super_admin || ! $user->pharmacy_id, 404);
        $password = $this->users->temporaryPassword();
        $user->forceFill(['password' => $password, 'must_change_password' => true, 'is_active' => true])->save();
        $this->audit($actor, $user->pharmacy, 'pharmacy.user_password_reset', ['user' => $user->username]);

        return $password;
    }

    /** Compte super-administrateur (premier démarrage ou commande artisan). */
    public function createSuperAdmin(array $data): User
    {
        $username = Str::lower(trim((string) ($data['username'] ?? '')));
        if (! preg_match('/^[a-z0-9._-]{3,30}$/', $username)) {
            throw ValidationException::withMessages(['username' => 'Identifiant de 3 à 30 caractères : lettres, chiffres, point, tiret.']);
        }
        if (User::whereNull('pharmacy_id')->where('username', $username)->exists()) {
            throw ValidationException::withMessages(['username' => 'Cet identifiant est déjà utilisé.']);
        }
        $user = User::create(['name' => trim($data['name']), 'username' => $username, 'password' => $data['password'],
            'is_super_admin' => true, 'is_active' => true, 'pharmacy_id' => null]);
        Cache::forget(PharmacySetupService::INSTALLED_KEY);

        return $user;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_super_admin, 403);
    }

    private function audit(User $actor, Pharmacy $pharmacy, string $action, array $new, ?array $old = null): void
    {
        AuditLog::create(['pharmacy_id' => $pharmacy->id, 'user_id' => $actor->id, 'action' => $action,
            'auditable_type' => 'pharmacy', 'auditable_id' => $pharmacy->id, 'old_values' => $old, 'new_values' => $new]);
    }
}
