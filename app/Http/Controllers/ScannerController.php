<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\InventorySession;
use App\Models\ScannerSession;
use App\Services\Scanner\ScannerException;
use App\Services\Scanner\ScannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Côté téléphone : appairage par QR puis page de scan (PWA) qui poste les scans en JSON.
 */
class ScannerController extends Controller
{
    public function __construct(private readonly ScannerService $scanner) {}

    /**
     * Point d'entrée de l'application installée (start_url) : reprend la session active du téléphone,
     * sinon lit le QR d'appairage avec la caméra — le QR ouvert par l'appareil photo partirait dans le navigateur.
     */
    public function home(Request $request)
    {
        $active = ScannerSession::where('user_id', $request->user()->id)->whereNotNull('confirmed_by')
            ->whereNull('revoked_at')->where('expires_at', '>', now())->latest('paired_at')->first();

        return $active ? redirect()->route('scanner.show', $active) : view('scanner.home');
    }

    /** URL encodée dans le QR. La connexion est demandée d'abord si besoin (redirection « intended »). */
    public function pair(Request $request, string $token)
    {
        try {
            $session = $this->scanner->claim($request->user(), $token, $request->userAgent());
        } catch (ScannerException $e) {
            return response()->view('scanner.error', ['message' => $e->getMessage()], 422);
        }

        return redirect()->route('scanner.show', $session);
    }

    public function show(Request $request, ScannerSession $session): View
    {
        $this->assertPhoneOwner($request, $session);

        $title = match ($session->mode) {
            'inventory' => 'Inventaire '.InventorySession::find($session->context_id)?->number,
            'receipt' => 'Réception '.GoodsReceipt::find($session->context_id)?->number,
            default => 'Scanner · caisse '.$session->register?->code,
        };

        return view('scanner.show', ['session' => $session, 'state' => ScannerService::state($session), 'title' => $title]);
    }

    /** Interrogée par le téléphone en attendant la confirmation du caissier. */
    public function status(Request $request, ScannerSession $session): JsonResponse
    {
        $this->assertPhoneOwner($request, $session);

        return response()->json(['state' => ScannerService::state($session->fresh()), 'last_sequence' => $session->last_sequence]);
    }

    public function scan(Request $request, ScannerSession $session): JsonResponse
    {
        $this->assertPhoneOwner($request, $session);

        try {
            return response()->json($this->scanner->ingest($request->user(), $session, $request->only('id', 'sequence', 'barcode', 'scanned_at', 'payload')));
        } catch (ScannerException $e) {
            return $this->error($e);
        }
    }

    /** Modes inventaire et réception : fiche du produit scanné avant la saisie du lot et de la quantité. */
    public function lookup(Request $request, ScannerSession $session): JsonResponse
    {
        $this->assertPhoneOwner($request, $session);

        try {
            return response()->json($this->scanner->lookup($request->user(), $session, (string) $request->query('code')));
        } catch (ScannerException $e) {
            return $this->error($e);
        }
    }

    private function error(ScannerException $e): JsonResponse
    {
        return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], $e->reason === 'session_inactive' ? 410 : 422);
    }

    private function assertPhoneOwner(Request $request, ScannerSession $session): void
    {
        abort_unless($session->user_id !== null && $session->user_id === $request->user()->id, 404);
    }
}
