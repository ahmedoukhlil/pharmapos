<?php

namespace App\Livewire\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Presentation;
use App\Models\Supplier;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\ReceiptException;
use App\Support\ExpiryDate;
use App\Support\ProductLookup;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Saisie d'une réception (ACH-03) : scan des produits, lot, péremption, quantité, décision.
 * Le brouillon est enregistré en base pour ne pas perdre une longue saisie.
 */
#[Layout('components.layouts.app')]
#[Title('Réception — PharmaPOS')]
class ReceiptEditor extends Component
{
    public ?string $receiptId = null;

    public string $supplierId = '';

    public string $locationId = '';

    public string $deliveryNoteRef = '';

    public string $notes = '';

    public string $search = '';

    /** @var array<int, array{presentation_id: string, quantity_packs: string, unit_price: string, lot_number: string, expiry: string, decision: string, anomaly: string}> */
    public array $lines = [];

    public ?string $error = null;

    public ?string $message = null;

    public function mount(?GoodsReceipt $receipt = null): void
    {
        if ($receipt?->exists) {
            abort_unless($receipt->pharmacy_id === auth()->user()->pharmacy_id, 404);
            $this->receiptId = $receipt->id;
            $this->supplierId = $receipt->supplier_id;
            $this->locationId = $receipt->location_id;
            $this->deliveryNoteRef = (string) $receipt->delivery_note_ref;
            $this->notes = (string) $receipt->notes;
            $this->lines = $receipt->lines()->orderBy('line_no')->get()->map(fn ($l) => [
                'presentation_id' => $l->presentation_id,
                'purchase_order_line_id' => $l->purchase_order_line_id,
                'quantity_packs' => (string) $l->quantity_packs,
                'unit_price' => (string) $l->unit_price,
                'lot_number' => (string) $l->lot_number,
                'expiry' => (string) $l->expiry_raw,
                'decision' => $l->decision,
                'anomaly' => (string) $l->anomaly,
            ])->all();

            return;
        }

        $this->supplierId = (string) $this->suppliers->first()?->id;
        $this->locationId = (string) $this->locations->firstWhere('type', 'reserve')?->id ?: (string) $this->locations->first()?->id;
    }

    #[Computed]
    public function receipt(): ?GoodsReceipt
    {
        return $this->receiptId
            ? GoodsReceipt::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.batch'])->find($this->receiptId)
            : null;
    }

    #[Computed]
    public function editable(): bool
    {
        return (! $this->receipt || $this->receipt->status === 'draft') && auth()->user()->hasPermission('purchase.manage');
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::where('pharmacy_id', auth()->user()->pharmacy_id)->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function locations(): Collection
    {
        return Location::where('pharmacy_id', auth()->user()->pharmacy_id)->where('is_active', true)
            ->whereIn('type', ['reserve', 'shelf', 'bin'])->orderBy('name')->get();
    }

    #[Computed]
    public function results(): Collection
    {
        return mb_strlen(trim($this->search)) >= 2 ? ProductLookup::find(auth()->user()->pharmacy_id, $this->search) : collect();
    }

    #[Computed]
    public function presentations(): Collection
    {
        return Presentation::with('medicine')->whereIn('id', array_column($this->lines, 'presentation_id'))->get()->keyBy('id');
    }

    /** Scan ou Entrée : ajoute le produit identifié (valeur passée par le client, cf. lecteurs USB). */
    public function addFromSearch(?string $term = null): void
    {
        $term = trim($term ?? $this->search);
        $this->search = '';
        if ($term === '') {
            return;
        }
        $match = ProductLookup::find(auth()->user()->pharmacy_id, $term);
        if ($match->count() === 1) {
            $this->add($match->first()->id);
        } elseif ($match->isEmpty()) {
            $this->error = "Produit inconnu « $term » : créez-le d'abord dans le catalogue.";
        } else {
            $this->search = $term;
        }
    }

    public function add(string $presentationId): void
    {
        $this->error = null;
        $this->search = '';

        // Scan répété du même produit sans lot saisi : on incrémente la dernière ligne
        $last = array_key_last($this->lines);
        if ($last !== null && $this->lines[$last]['presentation_id'] === $presentationId && $this->lines[$last]['lot_number'] === '') {
            $this->lines[$last]['quantity_packs'] = (string) ((int) $this->lines[$last]['quantity_packs'] + 1);

            return;
        }

        $presentation = Presentation::where('pharmacy_id', auth()->user()->pharmacy_id)->findOrFail($presentationId);
        $this->lines[] = [
            'presentation_id' => $presentation->id,
            'purchase_order_line_id' => null,
            'quantity_packs' => '1',
            'unit_price' => (string) $presentation->purchase_price,
            'lot_number' => '',
            'expiry' => '',
            'decision' => 'accepted',
            'anomaly' => '',
        ];
        unset($this->presentations);
    }

    /** Duplique une ligne pour saisir un second lot du même produit. */
    public function splitLine(int $i): void
    {
        if (isset($this->lines[$i])) {
            array_splice($this->lines, $i + 1, 0, [[...$this->lines[$i], 'lot_number' => '', 'expiry' => '', 'quantity_packs' => '1']]);
        }
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    /** Péremption interprétée, affichée à côté de la saisie pour contrôle visuel. */
    public function expiryPreview(string $raw): ?string
    {
        return $raw !== '' ? ExpiryDate::parse($raw, auth()->user()->pharmacy_id)?->format('d/m/Y') : null;
    }

    public function save(GoodsReceiptService $service): ?GoodsReceipt
    {
        $this->reset('error', 'message');
        try {
            $receipt = $service->saveDraft(auth()->user(), [
                'supplier_id' => $this->supplierId,
                'location_id' => $this->locationId,
                'delivery_note_ref' => $this->deliveryNoteRef ?: null,
                'notes' => $this->notes ?: null,
                'lines' => $this->lines,
            ], $this->receipt);
        } catch (ReceiptException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        $this->message = "Brouillon {$receipt->number} enregistré.";
        if (! $this->receiptId) {
            $this->receiptId = $receipt->id;
            $this->js("history.replaceState(null, '', '".route('receipts.edit', $receipt)."')");
        }
        unset($this->receipt);

        return $receipt;
    }

    public function validateReceipt(GoodsReceiptService $service): void
    {
        $receipt = $this->save($service);
        if (! $receipt) {
            return;
        }
        try {
            $receipt = $service->validate(auth()->user(), $receipt);
        } catch (ReceiptException $e) {
            $this->message = null;
            $this->error = $e->getMessage();

            return;
        }
        $this->message = "Réception {$receipt->number} validée : stock mis à jour.";
        unset($this->receipt, $this->editable);
    }

    public function cancel(GoodsReceiptService $service)
    {
        if (! $this->receipt) {
            return $this->redirectRoute('receipts.index');
        }
        try {
            $service->cancel(auth()->user(), $this->receipt);
        } catch (ReceiptException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('receipts.index');
    }

    public function render()
    {
        return view('livewire.purchasing.receipt-editor');
    }
}
