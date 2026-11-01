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
        // Les requêtes Livewire (route « default.livewire.update ») servent l'assistant lui-même
        $livewire = $request->routeIs('setup', 'livewire.*', '*.livewire.*') || $request->hasHeader('X-Livewire');
        if (! $livewire && ! PharmacySetupService::installed()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }
}
