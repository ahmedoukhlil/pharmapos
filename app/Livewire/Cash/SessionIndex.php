<?php

namespace App\Livewire\Cash;

use App\Models\CashSession;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Liste des sessions de caisse ; par défaut celles qui attendent une validation.
 */
#[Layout('components.layouts.app')]
#[Title('Sessions de caisse — PharmaPOS')]
class SessionIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'closed';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $sessions = CashSession::with(['register', 'opener', 'closer'])
            ->where('pharmacy_id', auth()->user()->pharmacy_id)
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest('opened_at')
            ->paginate(20);

        return view('livewire.cash.session-index', ['sessions' => $sessions]);
    }
}
