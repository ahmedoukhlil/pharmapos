<?php

namespace App\Livewire\Scanner;

use App\Models\ScannerSession;
use App\Services\Scanner\ScannerException;
use App\Services\Scanner\ScannerService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Pastille « Scanner smartphone » posée sur la caisse, une feuille d'inventaire ou une réception :
 * QR d'appairage, confirmation du téléphone, puis relève (interrogation courte) relayée à l'écran
 * parent par événement. Composant séparé pour que l'interrogation ne redessine pas tout l'écran.
 *
 * Événements émis : remote-scan (pos), remote-receipt-line (receipt), remote-inventory-count (inventory),
 * remote-scan-unknown, scanner-error.
 */
class ScannerPanel extends Component
{
    /** pos | inventory | receipt */
    #[Locked]
    public string $mode;

    /** Caisse (pos), inventaire ou réception. */
    #[Locked]
    public string $contextId;

    #[Locked]
    public ?string $sessionId = null;

    /** URL d'appairage en clair : n'existe que dans ce composant, le temps d'afficher le QR. */
    #[Locked]
    public ?string $pairingUrl = null;

    public bool $open = false;

    /** État et dernière séquence affichés au dernier rendu : l'interrogation ne redessine que s'ils changent. */
    #[Locked]
    public ?string $renderedState = null;

    #[Locked]
    public int $seenSequence = 0;

    public function mount(string $mode, string $contextId): void
    {
        abort_unless(isset(ScannerService::MODE_PERMISSIONS[$mode]), 404);
        $this->mode = $mode;
        $this->contextId = $contextId;
        $this->sessionId = ScannerSession::where('mode', $mode)->where($mode === 'pos' ? 'register_id' : 'context_id', $contextId)
            ->whereNull('revoked_at')->whereNotNull('confirmed_by')->where('expires_at', '>', now())->latest()->value('id');
        $this->seenSequence = (int) $this->scanner?->last_sequence;
    }

    #[Computed]
    public function scanner(): ?ScannerSession
    {
        return $this->sessionId ? ScannerSession::with('user')->find($this->sessionId) : null;
    }

    #[Computed]
    public function state(): ?string
    {
        return $this->scanner ? ScannerService::state($this->scanner) : null;
    }

    public function start(ScannerService $service): void
    {
        try {
            [$session, $token] = $service->createPairing(auth()->user(), $this->mode, $this->contextId);
        } catch (ScannerException $e) {
            $this->dispatch('scanner-error', message: $e->getMessage());

            return;
        }
        $this->sessionId = $session->id;
        $this->pairingUrl = route('scanner.pair', $token);
        $this->seenSequence = 0;
        $this->open = true;
        unset($this->scanner, $this->state);
    }

    public function confirm(ScannerService $service): void
    {
        $service->confirm(auth()->user(), $this->scanner);
        $this->pairingUrl = null;
        $this->open = false;
        unset($this->scanner, $this->state);
    }

    public function revoke(ScannerService $service): void
    {
        if ($this->scanner) {
            $service->revoke(auth()->user(), $this->scanner);
        }
        $this->reset('sessionId', 'pairingUrl', 'open');
        unset($this->scanner, $this->state);
    }

    /** Interrogée chaque seconde : n'émet un rendu que si quelque chose a changé. */
    public function poll(ScannerService $service): void
    {
        if ($this->state === 'active') {
            if ($this->mode === 'inventory') {
                // Les comptes sont déjà enregistrés par le téléphone : il suffit de rafraîchir la feuille
                if ($this->scanner->last_sequence > $this->seenSequence) {
                    $this->seenSequence = $this->scanner->last_sequence;
                    $this->dispatch('remote-inventory-count');
                }
            } else {
                foreach ($service->pull($this->scanner) as $event) {
                    match (true) {
                        ! $event->presentation_id => $this->dispatch('remote-scan-unknown', barcode: $event->barcode),
                        $this->mode === 'receipt' => $this->dispatch('remote-receipt-line', presentationId: $event->presentation_id, line: $event->payload),
                        default => $this->dispatch('remote-scan', presentationId: $event->presentation_id),
                    };
                }
            }
        }

        if ($this->renderedState === $this->state) {
            $this->skipRender();
        }
    }

    public function render()
    {
        $this->renderedState = $this->state;
        $host = request()->getHost();

        return view('livewire.scanner.panel', [
            'qr' => $this->pairingUrl && $this->state === 'waiting_phone'
                ? (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($this->pairingUrl)
                : null,
            'localhostWarning' => in_array($host, ['localhost', '127.0.0.1', '::1'], true),
        ]);
    }
}
