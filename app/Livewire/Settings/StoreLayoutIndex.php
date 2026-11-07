<?php

namespace App\Livewire\Settings;

use App\Models\CashSession;
use App\Models\Location;
use App\Models\Register;
use App\Models\StockBalance;
use App\Services\Settings\StoreLayoutService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Caisses et emplacements - PharmaPOS')]
class StoreLayoutIndex extends Component
{
    /** Formulaire ouvert : ['register'|'location', id|''] ou null. */
    public ?array $editing = null;

    public array $form = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('settings.manage'), 403);
    }

    public function editRegister(?string $id = null): void
    {
        $this->resetErrorBag();
        $r = $id ? $this->register($id) : null;
        $this->editing = ['register', $id ?? ''];
        $this->form = [
            'name' => $r?->name ?? 'Caisse '.(Register::where('pharmacy_id', auth()->user()->pharmacy_id)->count() + 1),
            'location_id' => $r?->location_id ?? $this->locations()->where('is_sellable', true)->where('is_active', true)->first()?->id,
            'printer_width' => $r?->printer_width ?? '80',
        ];
    }

    public function editLocation(?string $id = null): void
    {
        $this->resetErrorBag();
        $l = $id ? $this->location($id) : null;
        $this->editing = ['location', $id ?? ''];
        $this->form = [
            'name' => $l?->name ?? '',
            'type' => $l?->type ?? 'shelf',
            'is_sellable' => (bool) ($l?->is_sellable ?? true),
        ];
    }

    public function save(StoreLayoutService $service): void
    {
        [$kind, $id] = $this->editing ?? [null, null];
        match ($kind) {
            'register' => $service->saveRegister(auth()->user(), $this->form, $id ? $this->register($id) : null),
            'location' => $service->saveLocation(auth()->user(), $this->form, $id ? $this->location($id) : null),
            default => null,
        };
        $this->editing = null;
    }

    public function toggleRegister(string $id, StoreLayoutService $service): void
    {
        $r = $this->register($id);
        $service->setRegisterActive(auth()->user(), $r, ! $r->is_active);
    }

    public function toggleLocation(string $id, StoreLayoutService $service): void
    {
        $l = $this->location($id);
        $service->setLocationActive(auth()->user(), $l, ! $l->is_active);
    }

    private function register(string $id): Register
    {
        return Register::where('pharmacy_id', auth()->user()->pharmacy_id)->findOrFail($id);
    }

    private function location(string $id): Location
    {
        return Location::where('pharmacy_id', auth()->user()->pharmacy_id)->where('type', '!=', 'store')->findOrFail($id);
    }

    private function locations()
    {
        return Location::where('pharmacy_id', auth()->user()->pharmacy_id)->where('type', '!=', 'store')
            ->orderByDesc('is_active')->orderByRaw("FIELD(type, 'shelf', 'bin', 'reserve', 'quarantine')")->orderBy('name')->get();
    }

    public function render()
    {
        $pharmacyId = auth()->user()->pharmacy_id;
        $locations = $this->locations();

        return view('livewire.settings.store-layout-index', [
            'registers' => Register::where('pharmacy_id', $pharmacyId)->with('location')->orderByDesc('is_active')->orderBy('code')->get(),
            'openSessions' => CashSession::where('pharmacy_id', $pharmacyId)->where('status', 'open')->pluck('register_id')->flip(),
            'locations' => $locations,
            // Quantités en unités de base et nombre de lots par emplacement
            'stock' => StockBalance::whereIn('location_id', $locations->pluck('id'))->where('quantity', '>', 0)
                ->groupBy('location_id')->selectRaw('location_id, COUNT(DISTINCT batch_id) as batches, SUM(quantity) as units')
                ->get()->keyBy('location_id'),
            'types' => StoreLayoutService::LOCATION_TYPES,
        ]);
    }
}
