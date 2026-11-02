<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Connexion SaaS : code de la pharmacie + identifiant (les caissiers n'ont pas toujours d'e-mail).
 * Le code est mémorisé sur le poste ; laissé vide, il désigne le super-administrateur
 * (ou l'unique pharmacie s'il n'y en a qu'une).
 */
class LoginController extends Controller
{
    public const COOKIE = 'pharmacy_code';

    public function show(Request $request): View
    {
        return view('auth.login', ['code' => $request->cookie(self::COOKIE)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'pharmacy' => ['nullable', 'string', 'max:20'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
        $code = Str::upper(trim((string) ($credentials['pharmacy'] ?? '')));
        $username = Str::lower(trim($credentials['username']));

        $throttleKey = $code.'|'.$username.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'username' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($throttleKey).' secondes.',
            ]);
        }

        $user = $this->find($code, $username);
        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages(['username' => 'Code pharmacie, identifiant ou mot de passe incorrect.']);
        }
        if ($user->pharmacy && ! $user->pharmacy->isAccessible()) {
            throw ValidationException::withMessages(['username' => self::blockedMessage($user->pharmacy)]);
        }

        RateLimiter::clear($throttleKey);
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        if ($user->pharmacy) {
            Cookie::queue(Cookie::forever(self::COOKIE, $user->pharmacy->code));
        }

        return redirect()->intended($user->is_super_admin ? route('admin.pharmacies') : route('pos'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public static function blockedMessage(Pharmacy $pharmacy): string
    {
        return $pharmacy->is_active
            ? "L'abonnement de {$pharmacy->name} a expiré le {$pharmacy->subscription_ends_at->format('d/m/Y')}. Contactez votre fournisseur PharmaPOS."
            : "L'accès de {$pharmacy->name} est suspendu. Contactez votre fournisseur PharmaPOS.";
    }

    private function find(string $code, string $username): ?User
    {
        if ($code === '') {
            return User::whereNull('pharmacy_id')->where('is_super_admin', true)->where('username', $username)->first()
                ?? (Pharmacy::count() === 1 ? User::where('pharmacy_id', Pharmacy::value('id'))->where('username', $username)->first() : null);
        }
        $pharmacyId = Pharmacy::where('code', $code)->value('id');

        return $pharmacyId ? User::where('pharmacy_id', $pharmacyId)->where('username', $username)->first() : null;
    }
}
