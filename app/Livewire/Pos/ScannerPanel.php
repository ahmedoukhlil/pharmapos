<?php

namespace App\Livewire\Pos;

use App\Models\Register;
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
 * Pastille « Scanner smartphone » de la caisse : QR d'appairage, confirmation du téléphone,
 * puis relève des scans (interrogation courte) transmis à l'écran de caisse par événement.
 * Composant séparé pour que l'interrogation ne redessine pas tout le panier.
 */
class ScannerPanel extends Component
{
    #[Locked]
    public string $registerId;

    #[Locked]
    public ?string $sessionId = null;

    /** URL d'appairage en clair : n'existe que dans ce composant, le temps d'afficher le QR. */
    #[Locked]
    public ?string $pairingUrl = null;

    public bool $open = false;

    /** État affiché au dernier rendu : l'interrogation ne redessine que s'il change. */
    #[Locked]
    public ?string $renderedState = null;

    public function mount(string $registerId): void
    {
        $this->registerId = $registerId;
        $this->sessionId = ScannerSession::where('register_id', $registerId)->whereNull('revoked_at')
            ->whereNotNull('confirmed_by')->where('expires_at', '>', now())->latest()->value('id');
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
        $register = Register::findOrFail($this->registerId);
        try {
            [$session, $token] = $service->createPairing(auth()->user(), $register);
        } catch (ScannerException $e) {
            $this->dispatch('scanner-error', message: $e->getMessage());

            return;
        }
        $this->sessionId = $session->id;
        $this->pairingUrl = route('scanner.pair', $token);
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
            foreach ($service->pull($this->scanner) as $event) {
                $event->presentation_id
                    ? $this->dispatch('remote-scan', presentationId: $event->presentation_id)
                    : $this->dispatch('remote-scan-unknown', barcode: $event->barcode);
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

        return view('livewire.pos.scanner-panel', [
            'qr' => $this->pairingUrl && $this->state === 'waiting_phone'
                ? (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($this->pairingUrl)
                : null,
            'localhostWarning' => in_array($host, ['localhost', '127.0.0.1', '::1'], true),
        ]);
    }
}
