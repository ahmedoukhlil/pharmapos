<?php

namespace App\Services\Settings;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Comptes utilisateurs de l'officine : création avec mot de passe provisoire, changement de rôle,
 * désactivation (jamais de suppression : les ventes et le journal d'audit y font référence)
 * et réinitialisation du mot de passe.
 *
 * Garde-fous : on ne modifie ni son propre rôle ni son propre statut, il reste toujours un
 * propriétaire actif, et seul un propriétaire peut attribuer ou retirer le rôle de propriétaire.
 */
class UserService
{
    public const OWNER = 'owner';

    /** @return array{0: User, 1: string} l'utilisateur et son mot de passe provisoire */
    public function create(User $actor, array $data): array
    {
        $this->authorize($actor);
        $values = $this->validate($actor, $data);
        $role = $this->role($actor, $data['role'] ?? null);
        $password = $this->temporaryPassword();

        $user = DB::transaction(function () use ($actor, $values, $role, $password) {
            $user = User::create($values + [
                'pharmacy_id' => $actor->pharmacy_id,
                'password' => $password,
                'must_change_password' => true,
                'is_active' => true,
            ]);
            $user->roleAssignments()->create(['role_id' => $role->id, 'pharmacy_id' => $actor->pharmacy_id]);
            $this->audit($actor, 'user.created', $user, $values + ['role' => $role->slug]);

            return $user;
        });

        return [$user, $password];
    }

    public function update(User $actor, User $user, array $data): User
    {
        $this->authorize($actor, $user);
        $values = $this->validate($actor, $data, $user);
        $role = $this->role($actor, $data['role'] ?? null);
        $current = $this->currentRole($user);

        if ($current?->id !== $role->id) {
            if ($actor->is($user)) {
                throw ValidationException::withMessages(['role' => 'Vous ne pouvez pas modifier votre propre rôle.']);
            }
            if ($current?->slug === self::OWNER && $actor->roles()->where('slug', self::OWNER)->doesntExist()) {
                throw ValidationException::withMessages(['role' => 'Seul un propriétaire peut retirer le rôle de propriétaire.']);
            }
        }

        return DB::transaction(function () use ($actor, $user, $values, $role, $current) {
            $old = $user->only(array_keys($values)) + ['role' => $current?->slug];
            $user->update($values);
            if ($current?->id !== $role->id) {
                $user->roleAssignments()->where('pharmacy_id', $actor->pharmacy_id)->delete();
                $user->roleAssignments()->create(['role_id' => $role->id, 'pharmacy_id' => $actor->pharmacy_id]);
                $this->ensureOwner($actor->pharmacy_id);
            }
            $this->audit($actor, 'user.updated', $user, $values + ['role' => $role->slug], $old);

            return $user;
        });
    }

    public function setActive(User $actor, User $user, bool $active): void
    {
        $this->authorize($actor, $user);
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['user' => 'Vous ne pouvez pas désactiver votre propre compte.']);
        }
        if ($this->currentRole($user)?->slug === self::OWNER && $actor->roles()->where('slug', self::OWNER)->doesntExist()) {
            throw ValidationException::withMessages(['user' => 'Seul un propriétaire peut désactiver un propriétaire.']);
        }

        DB::transaction(function () use ($actor, $user, $active) {
            $user->update(['is_active' => $active]);
            $this->ensureOwner($actor->pharmacy_id);
            if (! $active) {
                // Déconnexion immédiate sur tous les postes
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->forceFill(['remember_token' => null])->save();
            }
            $this->audit($actor, $active ? 'user.activated' : 'user.deactivated', $user, ['is_active' => $active]);
        });
    }

    /** Génère un nouveau mot de passe provisoire, à changer à la prochaine connexion. */
    public function resetPassword(User $actor, User $user): string
    {
        $this->authorize($actor, $user);
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['user' => 'Changez votre propre mot de passe depuis « Mon mot de passe ».']);
        }
        $password = $this->temporaryPassword();
        DB::transaction(function () use ($actor, $user, $password) {
            $user->forceFill(['password' => $password, 'must_change_password' => true, 'remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit($actor, 'user.password_reset', $user, []);
        });

        return $password;
    }

    /** Rôles que cet utilisateur peut attribuer (le rôle propriétaire est réservé aux propriétaires). */
    public function assignableRoles(User $actor)
    {
        $isOwner = $actor->roles()->where('slug', self::OWNER)->exists();

        return Role::orderByDesc('max_discount_percent')->orderBy('name')->get()
            ->reject(fn ($r) => $r->slug === self::OWNER && ! $isOwner)->values();
    }

    public function currentRole(User $user): ?Role
    {
        return $user->roleAssignments()->where('pharmacy_id', $user->pharmacy_id)->with('role')->first()?->role;
    }

    public function temporaryPassword(): string
    {
        // Lisible au téléphone : pas de caractères ambigus (0/O, 1/l/I)
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';

        return collect(range(1, 10))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
    }

    private function validate(User $actor, array $data, ?User $user = null): array
    {
        $values = [
            'name' => trim((string) ($data['name'] ?? '')),
            'username' => Str::lower(trim((string) ($data['username'] ?? ''))),
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'email' => Str::lower(trim((string) ($data['email'] ?? ''))) ?: null,
        ];
        $errors = [];
        if ($values['name'] === '' || mb_strlen($values['name']) > 100) {
            $errors['name'] = 'Le nom est obligatoire (100 caractères maximum).';
        }
        if (! preg_match('/^[a-z0-9._-]{3,30}$/', $values['username'])) {
            $errors['username'] = 'Identifiant de 3 à 30 caractères : lettres minuscules, chiffres, point, tiret.';
        } elseif (User::where('username', $values['username'])->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()) {
            $errors['username'] = 'Cet identifiant est déjà utilisé.';
        }
        if ($values['email'] && ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse e-mail invalide.';
        } elseif ($values['email'] && User::where('email', $values['email'])->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()) {
            $errors['email'] = 'Cette adresse e-mail est déjà utilisée.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    private function role(User $actor, ?string $slug): Role
    {
        return $this->assignableRoles($actor)->firstWhere('slug', $slug)
            ?? throw ValidationException::withMessages(['role' => 'Choisissez un rôle.']);
    }

    private function ensureOwner(string $pharmacyId): void
    {
        $owners = User::where('pharmacy_id', $pharmacyId)->where('is_active', true)
            ->whereHas('roleAssignments', fn ($q) => $q->where('pharmacy_id', $pharmacyId)->whereHas('role', fn ($q) => $q->where('slug', self::OWNER)))
            ->exists();
        if (! $owners) {
            throw ValidationException::withMessages(['user' => "L'officine doit garder au moins un propriétaire actif."]);
        }
    }

    private function authorize(User $actor, ?User $user = null): void
    {
        if (! $actor->hasPermission('user.manage') || ($user && $user->pharmacy_id !== $actor->pharmacy_id)) {
            abort(403);
        }
    }

    private function audit(User $actor, string $action, User $user, array $new, array $old = []): void
    {
        AuditLog::create(['pharmacy_id' => $actor->pharmacy_id, 'user_id' => $actor->id, 'action' => $action,
            'auditable_type' => 'user', 'auditable_id' => $user->id, 'old_values' => $old ?: null, 'new_values' => $new ?: null]);
    }
}
