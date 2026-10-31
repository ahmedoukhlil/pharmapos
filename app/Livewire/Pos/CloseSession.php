<?php

namespace App\Livewire\Pos;

use App\Livewire\Concerns\RequiresReauth;
use App\Models\CashSession;
use App\Models\Register;
use App\Services\Cash\CashSessionException;
use App\Services\Cash\CashSessionService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Clôture de la session ouverte d'une caisse : mouvements d'espèces, comptage, écart (POS-10).
 */
#[Layout('components.layouts.app')]
#[Title('Clôture de caisse — PharmaPOS')]
class CloseSession extends Component
{
    use RequiresReauth;

    #[Url(as: 'caisse')]
    public string $registerCode = '';

    public string $countedCash = '';

    public string $notes = '';

    public string $movementType = 'cash_out';

    public string $movementAmount = '';

    public string $movementReason = '';

    public ?string $error = null;

    public ?string $message = null;

    public function mount(): void
    {
        if (! $this->register) {
            $this->registerCode = Register::where('pharmacy_id', auth()->user()->pharmacy_id)->orderBy('code')->value('code') ?? '';
            unset($this->register);
        }
    }

    #[Computed]
    public function register(): ?Register
    {
        return Register::where('pharmacy_id', auth()->user()->pharmacy_id)->where('code', $this->registerCode)->first();
    }

    #[Computed]
    public function session(): ?CashSession
    {
        return $this->register
            ? CashSession::with('opener')->where('register_id', $this->register->id)->where('status', 'open')->latest('opened_at')->first()
            : null;
    }

    #[Computed]
    public function summary(): ?array
    {
        return $this->session ? app(CashSessionService::class)->summary($this->session) : null;
    }

    #[Computed]
    public function difference(): ?int
    {
        if (! $this->summary || ! is_numeric($this->countedCash)) {
            return null;
        }

        return (int) round((float) $this->countedCash * 100) - $this->summary['expected_cash'];
    }

    public function recordMovement(CashSessionService $service): void
    {
        if (! $this->reauthenticated('cash.movement', 'recordMovement')) {
            return;
        }
        $this->reset('error', 'message');
        try {
            $service->recordMovement($this->session, auth()->user(), $this->movementType, $this->movementAmount ?: '0', $this->movementReason);
        } catch (CashSessionException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->reset('movementAmount', 'movementReason');
        $this->message = 'Mouvement enregistré.';
        unset($this->summary);
    }

    public function close(CashSessionService $service)
    {
        $this->reset('error', 'message');
        if (! $this->session) {
            $this->error = 'Aucune session ouverte sur cette caisse.';

            return null;
        }
        try {
            $session = $service->close($this->session, auth()->user(), $this->countedCash, $this->notes ?: null);
        } catch (CashSessionException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('sessions.show', $session);
    }

    public function render()
    {
        return view('livewire.pos.close-session', [
            'canMove' => auth()->user()->hasPermission('cash.movement'),
        ]);
    }
}
