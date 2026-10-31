<?php

namespace App\Livewire\Inventory;

use App\Models\Batch;
use App\Models\InventorySession;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryService;
use App\Support\ProductLookup;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Feuille de comptage et revue des écarts (STK-04, STK-06).
 * Comptage « à l'aveugle » : le stock théorique n'est montré qu'aux personnes habilitées à approuver.
 */
#[Layout('components.layouts.app')]
#[Title('Inventaire — PharmaPOS')]
class InventorySheet extends Component
{
    public InventorySession $session;

    /** Produit scanné : la feuille n'affiche que ses lignes */
    public ?string $presentationFilter = null;

    public bool $onlyUncounted = false;

    public bool $uncountedAsZero = false;

    public string $comment = '';

    public ?string $error = null;

    public ?string $message = null;

    public function mount(InventorySession $session): void
    {
        abort_unless($session->pharmacy_id === auth()->user()->pharmacy_id, 404);
        $this->session = $session;
    }

    #[Computed]
    public function can(): array
    {
        return [
            'count' => auth()->user()->hasPermission('inventory.count'),
            'approve' => auth()->user()->hasPermission('inventory.approve'),
        ];
    }

    #[Computed]
    public function recountRequested(): bool
    {
        return $this->session->approvals()->where('decision', 'recount')->exists();
    }

    #[Computed]
    public function lines(): Collection
    {
        return $this->session->lines()
            ->with(['presentation.medicine', 'batch', 'location'])
            ->when($this->presentationFilter, fn ($q) => $q->where('presentation_id', $this->presentationFilter))
            ->when($this->onlyUncounted, fn ($q) => $q->whereNull($this->recountRequested ? 'recount_quantity' : 'counted_quantity'))
            ->when($this->session->status === 'counting' && $this->recountRequested && ! $this->presentationFilter,
                fn ($q) => $q->where('variance', '!=', 0))
            ->get()
            ->sortBy(fn ($l) => [$l->presentation->medicine->brand_name, $l->batch->expiry_date->format('Ymd')])
            ->values();
    }

    /** Lots du produit scanné absents de la feuille (trouvés en rayon mais pas en théorique). */
    #[Computed]
    public function missingBatches(): Collection
    {
        if (! $this->presentationFilter || $this->session->status !== 'counting') {
            return collect();
        }

        return Batch::where('presentation_id', $this->presentationFilter)
            ->whereNotIn('id', $this->lines->pluck('batch_id'))
            ->whereNotIn('status', ['destroyed'])
            ->orderBy('expiry_date')
            ->get();
    }

    #[Computed]
    public function progress(): array
    {
        $lines = $this->session->lines();

        return ['total' => (clone $lines)->count(), 'counted' => (clone $lines)->whereNotNull('counted_quantity')->count()];
    }

    public function scan(string $term): void
    {
        $this->reset('error', 'message');
        $match = ProductLookup::find($this->session->pharmacy_id, $term);
        if ($match->count() !== 1) {
            $this->error = $match->isEmpty() ? "Produit inconnu « $term »." : "Plusieurs produits pour « $term » : scannez le code-barres.";

            return;
        }
        $this->presentationFilter = $match->first()->id;
        unset($this->lines, $this->missingBatches);
    }

    public function clearFilter(): void
    {
        $this->presentationFilter = null;
        unset($this->lines, $this->missingBatches);
    }

    /** Saisie du compte d'une ligne (en unités de base). */
    public function count(string $lineId, $quantity, InventoryService $service): void
    {
        $this->reset('error', 'message');
        if ($quantity === '' || $quantity === null) {
            return;
        }
        try {
            $service->count(auth()->user(), $this->session, ['line_id' => $lineId, 'quantity' => $quantity]);
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();
        }
        unset($this->lines, $this->progress);
    }

    public function addBatch(string $batchId, InventoryService $service): void
    {
        $this->reset('error', 'message');
        try {
            $service->count(auth()->user(), $this->session, [
                'batch_id' => $batchId,
                'location_id' => $this->session->location_id ?? $this->lines->first()?->location_id,
                'quantity' => 0,
            ]);
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();
        }
        unset($this->lines, $this->missingBatches, $this->progress);
    }

    public function submit(InventoryService $service): void
    {
        $this->run(fn () => $service->submit(auth()->user(), $this->session, $this->uncountedAsZero), 'Comptage terminé : écarts calculés.');
    }

    public function recount(InventoryService $service): void
    {
        $this->run(fn () => $service->requestRecount(auth()->user(), $this->session, $this->comment ?: null), 'Recomptage demandé sur les lignes en écart.');
    }

    public function approve(InventoryService $service): void
    {
        $this->run(fn () => $service->approve(auth()->user(), $this->session, $this->comment ?: null), 'Inventaire approuvé : stock ajusté.');
    }

    public function cancel(InventoryService $service): void
    {
        $this->run(fn () => $service->cancel(auth()->user(), $this->session), 'Inventaire annulé.');
    }

    private function run(callable $action, string $success): void
    {
        $this->reset('error', 'message');
        try {
            $this->session = $action();
            $this->message = $success;
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();
        }
        $this->presentationFilter = null;
        unset($this->lines, $this->progress, $this->recountRequested);
    }

    public function render()
    {
        $this->session->refresh();

        return view('livewire.inventory.inventory-sheet');
    }
}
