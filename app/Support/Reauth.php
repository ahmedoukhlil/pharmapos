<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Réauthentification des opérations sensibles (PRD §4 : annulations, remboursements, ajustements,
 * changements de prix, quarantaine…). Une confirmation du mot de passe reste valable quelques minutes
 * (paramètre security.reauth_minutes, 5 par défaut) pour la session du navigateur.
 */
class Reauth
{
    public const SESSION_KEY = 'auth.reauth_at';

    public static function requires(string $permission): bool
    {
        return Cache::rememberForever("permission.reauth.$permission",
            fn () => (bool) Permission::where('slug', $permission)->value('requires_reauth'));
    }

    public static function isFresh(User $user): bool
    {
        $at = session(self::SESSION_KEY);
        $minutes = (int) PharmacySettings::get($user->pharmacy_id, 'security.reauth_minutes', 5);

        return $at !== null && now()->timestamp - $at < $minutes * 60;
    }

    public static function confirm(User $user, string $password, string $permission): void
    {
        $key = 'reauth:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'reauthPassword' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($key).' secondes.',
            ]);
        }

        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($key);
            self::audit($user, 'reauth.failed', $permission);
            throw ValidationException::withMessages(['reauthPassword' => 'Mot de passe incorrect.']);
        }

        RateLimiter::clear($key);
        session()->put(self::SESSION_KEY, now()->timestamp);
        self::audit($user, 'reauth.confirmed', $permission);
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    private static function audit(User $user, string $action, string $permission): void
    {
        AuditLog::create([
            'pharmacy_id' => $user->pharmacy_id,
            'user_id' => $user->id,
            'action' => $action,
            'auditable_type' => 'user',
            'auditable_id' => $user->id,
            'new_values' => ['permission' => $permission, 'ip' => request()->ip()],
        ]);
    }
}
