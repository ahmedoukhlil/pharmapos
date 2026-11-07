<?php

namespace App\Livewire\Inventory;

use App\Models\InventorySession;
use App\Models\Location;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Inventaires - PharmaPOS')]
class InventoryIndex extends Component
{
    use WithPagination;

    public string $scope = 'partial';

    public string $locationId = '';

    public ?string $error = null;

    public function open(InventoryService $service)
    {
        $this->error = null;
        try {
            $session = $service->open(auth()->user(), ['scope' => $this->scope, 'location_id' => $this->locationId ?: null]);
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('inventories.show', $session);
    }

    public function render()
    {
        $pharmacyId = auth()->user()->pharmacy_id;

        return view('livewire.inventory.inventory-index', [
            'sessions' => InventorySession::with('location')->withCount('lines')
                ->where('pharmacy_id', $pharmacyId)->latest('snapshot_at')->paginate(20),
            'locations' => Location::where('pharmacy_id', $pharmacyId)->where('is_active', true)->where('type', '!=', 'store')->orderBy('name')->get(),
            'canCount' => auth()->user()->hasPermission('inventory.count'),
        ]);
    }
}
