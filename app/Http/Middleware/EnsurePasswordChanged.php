<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tant qu'un mot de passe provisoire n'a pas été remplacé, seul l'écran de changement
 * (et la déconnexion) est accessible.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! $request->routeIs('password.*', 'logout')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Changement de mot de passe requis.'], 403)
                : redirect()->route('password.edit');
        }

        return $next($request);
    }
}
