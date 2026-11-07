<?php

namespace App\Livewire\Inventory;

use App\Models\Presentation;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\StockTransferService;
use App\Support\ProductLookup;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Transfert de stock : on choisit le produit (recherche, douchette ou caméra), le sens
 * (Réserve → Rayon…), puis la quantité par lot, le plus proche de la péremption en premier.
 */
#[Layout('components.layouts.app')]
#[Title('Transfert de stock — PharmaPOS')]
class StockTransfer extends Component
{
    public string $search = '';

    public ?string $presentationId = null;

    public string $fromId = '';

    public string $toId = '';

    /** batch_id => quantité saisie */
    public array $quantities = [];

    /** 'pack' (boîtes) ou 'unit' */
    public string $unit = 'pack';

    public ?string $error = null;

    public ?string $message = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('stock.transfer'), 403);
        $types = $this->locations->keyBy('type');
        $this->fromId = (string) ($types->get('reserve')?->id ?? $this->locations->first()?->id);
        $this->toId = (string) ($types->get('shelf')?->id ?? $this->locations->firstWhere('id', '!=', $this->fromId)?->id);
    }

    #[Computed]
    public function locations()
    {
        return StockTransferService::locations(auth()->user()->pharmacy_id);
    }

    #[Computed]
    public function results()
    {
        return $this->presentationId || mb_strlen(trim($this->search)) < 2
            ? collect()
            : ProductLookup::find(auth()->user()->pharmacy_id, $this->search);
    }

    #[Computed]
    public function presentation(): ?Presentation
    {
        return $this->presentationId ? Presentation::with('medicine')->where('pharmacy_id', auth()->user()->pharmacy_id)->find($this->presentationId) : null;
    }

    /** Lots du produit avec leur stock dans chaque emplacement, péremption la plus proche d'abord. */
    #[Computed]
    public function batches()
    {
        if (! $this->presentationId) {
            return collect();
        }
        $balances = StockBalance::with('batch')->where('presentation_id', $this->presentationId)
            ->whereIn('location_id', $this->locations->pluck('id'))->get();

        return $balances->groupBy('batch_id')->map(fn ($rows) => [
            'batch' => $rows->first()->batch,
            'stock' => $rows->mapWithKeys(fn ($b) => [$b->location_id => $b->quantity - $b->reserved_quantity])->all(),
        ])->filter(fn ($b) => $b['batch']->status === 'available' && array_sum($b['stock']) > 0)
            ->sortBy(fn ($b) => $b['batch']->expiry_date)->values();
    }

    #[Computed]
    public function recent()
    {
        return StockMovement::with(['batch', 'presentation.medicine', 'user'])
            ->where('pharmacy_id', auth()->user()->pharmacy_id)->where('type', 'transfer_in')
            ->latest('occurred_at')->limit(10)->get();
    }

    public function updatedSearch(): void
    {
        $this->presentationId = null;
        $this->message = null;
    }

    public function submitSearch(): void
    {
        if (trim($this->search) !== '') {
            $this->scan(trim($this->search));
        }
    }

    /** Recherche validée, douchette ou caméra. */
    public function scan(string $term): void
    {
        $this->error = null;
        $this->message = null;
        $match = ProductLookup::find(auth()->user()->pharmacy_id, $term);
        if ($match->count() === 1) {
            $this->choose($match->first()->id);

            return;
        }
        $this->presentationId = null;
        $this->search = $term;
        $this->error = $match->isEmpty() ? "Produit inconnu « $term »." : null;
    }

    public function choose(string $id): void
    {
        $this->presentationId = $id;
        $this->search = '';
        $this->quantities = [];
        $this->error = null;
        $this->unit = ($this->presentation?->units_per_pack ?? 1) > 1 ? 'pack' : 'unit';
    }

    public function clear(): void
    {
        $this->reset('presentationId', 'search', 'quantities', 'error', 'message');
    }

    public function swap(): void
    {
        [$this->fromId, $this->toId] = [$this->toId, $this->fromId];
        $this->quantities = [];
    }

    /** Tout le stock du lot dans l'emplacement de départ. */
    public function takeAll(string $batchId): void
    {
        $units = (int) ($this->batches->firstWhere('batch.id', $batchId)['stock'][$this->fromId] ?? 0);
        $per = max(1, (int) $this->presentation?->units_per_pack);
        $this->quantities[$batchId] = $this->unit === 'pack' && $units % $per === 0 ? $units / $per : $units;
        if ($this->unit === 'pack' && $units % $per !== 0) {
            $this->unit = 'unit';
        }
    }

    public function transfer(string $batchId, StockTransferService $service): void
    {
        $this->error = null;
        $this->message = null;
        $qty = (int) ($this->quantities[$batchId] ?? 0);
        $per = $this->unit === 'pack' ? max(1, (int) $this->presentation?->units_per_pack) : 1;
        try {
            $service->transfer(auth()->user(), $batchId, $this->fromId, $this->toId, $qty * $per);
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $names = $this->locations->keyBy('id');
        $this->message = "{$qty} ".($this->unit === 'pack' ? 'boîte(s)' : 'unité(s)')." transférée(s) : {$names[$this->fromId]->name} → {$names[$this->toId]->name}.";
        unset($this->quantities[$batchId], $this->batches, $this->recent);
    }

    public function render()
    {
        return view('livewire.inventory.stock-transfer');
    }
}
