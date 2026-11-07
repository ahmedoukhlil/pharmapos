<?php

namespace App\Livewire\Cash;

use App\Livewire\Concerns\RequiresReauth;
use App\Models\CashSession;
use App\Services\Cash\CashSessionException;
use App\Services\Cash\CashSessionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Rapport de session (imprimable) et validation par un responsable.
 */
#[Layout('components.layouts.app')]
#[Title('Rapport de session - PharmaPOS')]
class SessionShow extends Component
{
    use RequiresReauth;

    public CashSession $session;

    public string $comment = '';

    public ?string $error = null;

    public function mount(CashSession $session): void
    {
        abort_unless($session->pharmacy_id === auth()->user()->pharmacy_id, 404);
        $this->session = $session;
    }

    public function validateSession(CashSessionService $service): void
    {
        if (! $this->reauthenticated('cash.validate', 'validateSession')) {
            return;
        }
        $this->error = null;
        try {
            $this->session = $service->validate($this->session, auth()->user(), $this->comment ?: null);
        } catch (CashSessionException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(CashSessionService $service)
    {
        $this->session->load(['register', 'opener', 'closer', 'validator', 'movements.user']);

        return view('livewire.cash.session-show', [
            'summary' => $service->summary($this->session),
            'canValidate' => auth()->user()->hasPermission('cash.validate'),
        ]);
    }
}
