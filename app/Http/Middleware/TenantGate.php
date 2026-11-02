<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\LoginController;
use App\Models\Pharmacy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS : le super-administrateur reste dans la console d'administration ; l'utilisateur d'une
 * pharmacie suspendue ou dont l'abonnement a expiré est déconnecté à sa requête suivante.
 */
class TenantGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $livewire = $request->routeIs('livewire.*', '*.livewire.*') || $request->hasHeader('X-Livewire');

        if (! $user || $request->routeIs('logout')) {
            return $next($request);
        }

        if ($user->is_super_admin) {
            // Les composants des pharmacies exigent des permissions qu'il n'a pas
            return $livewire || $request->routeIs('admin.*', 'backups.*', 'password.*')
                ? $next($request)
                : redirect()->route('admin.pharmacies');
        }

        // Relue à chaque requête : une suspension prend effet sans attendre la fin de session
        $pharmacy = $user->pharmacy_id ? Pharmacy::find($user->pharmacy_id) : null;
        if (! $pharmacy?->isAccessible()) {
            $message = $pharmacy ? LoginController::blockedMessage($pharmacy) : 'Compte sans pharmacie.';
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $livewire || $request->expectsJson()
                ? response()->json(['message' => $message], 403)
                : redirect()->route('login')->with('blocked', $message);
        }

        return $next($request);
    }
}
