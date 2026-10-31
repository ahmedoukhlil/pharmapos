<?php

namespace App\Livewire\Alerts;

use App\Services\Alerts\AlertService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Alertes — PharmaPOS')]
class AlertIndex extends Component
{
    #[Url]
    public string $tab = 'expiry';

    public function render(AlertService $alerts)
    {
        $user = auth()->user();
        abort_unless($user->hasPermission('stock.view'), 403);
        $pharmacyId = $user->pharmacy_id;
        $canCash = $user->hasPermission('cash.validate');

        return view('livewire.alerts.alert-index', [
            'expiring' => $alerts->expiring($pharmacyId)->groupBy('bucket'),
            'lowStock' => $alerts->lowStock($pharmacyId),
            'variances' => $canCash ? $alerts->cashVariances($pharmacyId) : collect(),
            'canCash' => $canCash,
        ]);
    }
}
