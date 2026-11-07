<?php

namespace App\Livewire\Pos;

use App\Models\Sale;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Ventes en attente de la session de caisse, rafraîchies toutes les quelques secondes :
 * un préparateur envoie un panier, il apparaît chez le caissier sans recharger la page.
 */
class HeldSales extends Component
{
    public string $sessionId;

    /** Vente reprise dans le panier, à ne pas lister. */
    #[Reactive]
    public ?string $exclude = null;

    #[On('held-changed')]
    public function refresh(): void {}

    public function render()
    {
        $sales = Sale::with('user')->withCount('items')
            ->where('cash_session_id', $this->sessionId)->where('status', 'on_hold')
            ->when($this->exclude, fn ($q) => $q->whereKeyNot($this->exclude))
            ->orderBy('held_at')->get();

        return view('livewire.pos.held-sales', [
            'sales' => $sales,
            // Envoyées par quelqu'un d'autre : signalées et annoncées par un bip
            'incoming' => $sales->where('user_id', '!=', auth()->id())->pluck('id')->values(),
        ]);
    }
}
