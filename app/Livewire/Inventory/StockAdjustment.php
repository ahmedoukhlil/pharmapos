<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\RequiresReauth;
use App\Models\Presentation;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\StockAdjustmentService;
use App\Support\ProductLookup;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Correction de stock : produit (recherche, douchette ou caméra), lot et emplacement,
 * quantité à retirer ou à ajouter, motif obligatoire. Mot de passe redemandé.
 */
#[Layout('components.layouts.app')]
#[Title('Correction de stock - PharmaPOS')]
class StockAdjustment extends Component
{
    use RequiresReauth;

    public string $search = '';

    public ?string $presentationId = null;

    /** Ligne choisie : « batchId|locationId » */
    public string $target = '';

    public string $direction = 'out';

    public string $reason = 'breakage';

    public string $quantity = '';

    public string $comment = '';

    /** 'pack' (boîtes) ou 'unit' */
    public string $unit = 'pack';

    public ?string $error = null;

    public ?string $message = null;

    public function mount(): void
    {
        $u = auth()->user();
        abort_unless($u->hasPermission('stock.adjust') || $u->hasPermission('batch.destroy'), 403);
        if (! $u->hasPermission('stock.adjust')) {
            $this->reason = 'expired';
        }
    }

    #[Computed]
    public function locations()
    {
        return StockAdjustmentService::locations(auth()->user()->pharmacy_id);
    }

    /** Motifs permis à cet utilisateur pour le sens choisi. */
    #[Computed]
    public function reasons(): array
    {
        $u = auth()->user();

        return collect(StockAdjustmentService::REASONS)
            ->filter(fn ($r) => in_array($this->direction, $r[1], true) && $u->hasPermission($r[3]))
            ->map(fn ($r) => $r[0])->all();
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

    /** Stock par lot et emplacement, péremption la plus proche d'abord. */
    #[Computed]
    public function rows()
    {
        if (! $this->presentationId) {
            return collect();
        }
        $names = $this->locations->keyBy('id');

        return StockBalance::with('batch')->where('presentation_id', $this->presentationId)
            ->whereIn('location_id', $names->keys())
            ->where(fn ($q) => $q->where('quantity', '!=', 0)->orWhere('reserved_quantity', '>', 0))
            ->get()
            ->filter(fn ($b) => $b->batch && ! in_array($b->batch->status, ['destroyed', 'returned'], true))
            ->sortBy(fn ($b) => [$b->batch->expiry_date, $names[$b->location_id]->name])
            ->map(fn ($b) => (object) [
                'key' => $b->batch_id.'|'.$b->location_id,
                'batch' => $b->batch,
                'location' => $names[$b->location_id],
                'quantity' => (int) $b->quantity,
                'available' => (int) ($b->quantity - $b->reserved_quantity),
            ])->values();
    }

    #[Computed]
    public function recent()
    {
        return StockMovement::with(['batch', 'presentation.medicine', 'user', 'location'])
            ->where('pharmacy_id', auth()->user()->pharmacy_id)->where('source_type', 'stock_adjustment')
            ->latest('occurred_at')->limit(10)->get();
    }

    public function updatedSearch(): void
    {
        $this->presentationId = null;
        $this->message = null;
    }

    public function updatedDirection(): void
    {
        unset($this->reasons);
        if (! array_key_exists($this->reason, $this->reasons)) {
            $this->reason = (string) array_key_first($this->reasons);
        }
    }

    public function submitSearch(): void
    {
        if (trim($this->search) !== '') {
            $this->scan(trim($this->search));
        }
    }

    public function scan(string $term): void
    {
        $this->error = $this->message = null;
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
        $this->error = null;
        $this->target = (string) $this->rows->first()?->key;
        $this->unit = ($this->presentation?->units_per_pack ?? 1) > 1 ? 'pack' : 'unit';
    }

    public function clear(): void
    {
        $this->reset('presentationId', 'search', 'target', 'quantity', 'comment', 'error', 'message');
    }

    public function apply(StockAdjustmentService $service): void
    {
        $this->error = $this->message = null;
        $permission = StockAdjustmentService::REASONS[$this->reason][3] ?? 'stock.adjust';
        if (! $this->reauthenticated($permission, 'apply')) {
            return;
        }
        [$batchId, $locationId] = array_pad(explode('|', $this->target), 2, '');
        $qty = (int) $this->quantity;
        $per = $this->unit === 'pack' ? max(1, (int) $this->presentation?->units_per_pack) : 1;
        try {
            $service->adjust(auth()->user(), $batchId, $locationId, $this->direction, $qty * $per, $this->reason, $this->comment);
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $row = $this->rows->firstWhere('key', $this->target);
        $this->message = ($this->direction === 'out' ? 'Retiré' : 'Ajouté').' : '.$qty.' '.($this->unit === 'pack' ? 'boîte(s)' : 'unité(s)')
            .' du lot '.$row?->batch->lot_number.' ('.$row?->location->name.').';
        $this->quantity = '';
        $this->comment = '';
        unset($this->rows, $this->recent);
    }

    public function render()
    {
        return view('livewire.inventory.stock-adjustment');
    }
}
