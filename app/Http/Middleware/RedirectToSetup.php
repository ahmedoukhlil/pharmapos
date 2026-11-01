<?php

namespace App\Http\Middleware;

use App\Services\Settings\PharmacySetupService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tant qu'aucune officine n'existe, toute page mène à l'assistant de premier démarrage.
 */
class RedirectToSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('setup', 'livewire.*') && ! PharmacySetupService::installed()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }
}
