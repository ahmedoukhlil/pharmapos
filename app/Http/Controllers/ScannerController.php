<?php

namespace App\Http\Controllers;

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

        return view('scanner.show', ['session' => $session->load('register'), 'state' => ScannerService::state($session)]);
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
            return response()->json($this->scanner->ingest($request->user(), $session, $request->only('id', 'sequence', 'barcode', 'scanned_at')));
        } catch (ScannerException $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], $e->reason === 'session_inactive' ? 410 : 422);
        }
    }

    private function assertPhoneOwner(Request $request, ScannerSession $session): void
    {
        abort_unless($session->user_id !== null && $session->user_id === $request->user()->id, 404);
    }
}
