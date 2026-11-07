<?php

namespace App\Livewire\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Presentation;
use App\Models\Supplier;
use App\Services\DeliveryNotes\DeliveryNoteException;
use App\Services\DeliveryNotes\DeliveryNoteReader;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Purchasing\ReceiptException;
use App\Support\ExpiryDate;
use App\Support\ProductLookup;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Saisie d'une réception (ACH-03) : scan des produits, lot, péremption, quantité, décision.
 * Le brouillon est enregistré en base pour ne pas perdre une longue saisie.
 */
#[Layout('components.layouts.app')]
#[Title('Réception - PharmaPOS')]
class ReceiptEditor extends Component
{
    use WithFileUploads;

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

    /** Pages du bordereau (photos ou PDF) à faire lire. */
    public array $noteFiles = [];

    /** Bordereau lu, en attente de contrôle avant ajout à la réception. */
    public ?array $note = null;

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

    /**
     * Lot saisi sur le smartphone (SCN-08) : complète la première ligne du produit encore sans lot
     * (lignes pré-remplies depuis la commande), sinon ajoute une ligne. Le brouillon est enregistré aussitôt.
     */
    #[On('remote-receipt-line')]
    public function addRemoteLine(string $presentationId, array $line, GoodsReceiptService $service): void
    {
        if (! $this->editable) {
            return;
        }
        $fields = ['lot_number' => $line['lot_number'] ?? '', 'expiry' => $line['expiry'] ?? '', 'quantity_packs' => (string) ($line['quantity_packs'] ?? 1)];

        foreach ($this->lines as $i => $l) {
            if ($l['presentation_id'] === $presentationId && $l['lot_number'] === '') {
                $this->lines[$i] = [...$l, ...$fields];
                $this->save($service);

                return;
            }
        }

        $this->add($presentationId);
        $last = array_key_last($this->lines);
        $this->lines[$last] = [...$this->lines[$last], ...$fields];
        $this->save($service);
    }

    #[On('remote-scan-unknown')]
    public function remoteUnknown(string $barcode): void
    {
        $this->error = "Code inconnu scanné par le téléphone : $barcode";
    }

    #[On('scanner-error')]
    public function scannerError(string $message): void
    {
        $this->error = $message;
    }

    /** Lecture du bordereau par Claude : propose un tableau à contrôler, sans rien enregistrer. */
    public function readNote(DeliveryNoteReader $reader): void
    {
        abort_unless($this->editable, 403);
        $this->error = null;
        $this->validate([
            'noteFiles' => ['required', 'array', 'max:10'],
            'noteFiles.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
        ], [], ['noteFiles' => 'bordereau', 'noteFiles.*' => 'page']);

        $files = array_map(fn ($f) => ['path' => $f->getRealPath(), 'mime' => $f->getMimeType()], $this->noteFiles);
        try {
            $this->note = $reader->read(auth()->user(), Supplier::findOrFail($this->supplierId), $files);
        } catch (DeliveryNoteException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->noteFiles = [];
        if ($this->note['lines'] === []) {
            $this->note = null;
            $this->error = 'Aucune ligne de produit trouvée sur ce document.';
        }
    }

    public function removeNoteLine(int $i): void
    {
        unset($this->note['lines'][$i]);
        $this->note['lines'] = array_values($this->note['lines']);
    }

    public function discardNote(): void
    {
        $this->note = null;
    }

    /** Ajoute les lignes contrôlées à la réception et mémorise les associations pour ce fournisseur. */
    public function applyNote(DeliveryNoteReader $reader): void
    {
        abort_unless($this->editable && $this->note, 403);
        $pending = collect($this->note['lines'])->filter(fn ($l) => $l['presentation_id'] === '')->count();
        if ($pending) {
            $this->error = "$pending ligne(s) sans produit : choisissez le produit ou retirez la ligne.";

            return;
        }

        $owned = Presentation::where('pharmacy_id', auth()->user()->pharmacy_id)
            ->whereIn('id', array_column($this->note['lines'], 'presentation_id'))->pluck('id')->all();
        foreach ($this->note['lines'] as $l) {
            if (! in_array($l['presentation_id'], $owned, true)) {
                continue;
            }
            $reader->learn(auth()->user(), $this->supplierId, $l['designation'], $l['presentation_id']);
            $this->lines[] = [
                'presentation_id' => $l['presentation_id'],
                'purchase_order_line_id' => null,
                'quantity_packs' => (string) (int) round((float) $l['quantity']),
                'unit_price' => (string) $l['unit_price'],
                'lot_number' => (string) $l['lot'],
                'expiry' => (string) $l['expiry'],
                'decision' => 'accepted',
                'anomaly' => '',
            ];
        }
        if ($this->deliveryNoteRef === '' && $this->note['number'] !== '') {
            $this->deliveryNoteRef = mb_substr($this->note['number'], 0, 50);
        }
        $this->message = count($this->note['lines']).' ligne(s) du bordereau ajoutée(s) : vérifiez puis enregistrez.';
        $this->note = null;
        unset($this->presentations);
    }

    /** Produit créé depuis une ligne inconnue du bordereau : la ligne lui est associée. */
    #[On('quick-product-created')]
    public function noteProductCreated(string $presentationId, ?string $ref = null): void
    {
        if ($this->note && $ref !== null && isset($this->note['lines'][(int) $ref])) {
            $this->note['lines'][(int) $ref] = [...$this->note['lines'][(int) $ref], 'presentation_id' => $presentationId, 'status' => 'ok', 'source' => 'created'];
        }
        unset($this->catalogue);
    }

    /** Produits proposés dans la liste de choix d'une ligne inconnue du bordereau. */
    #[Computed]
    public function catalogue(): Collection
    {
        return Presentation::where('pharmacy_id', auth()->user()->pharmacy_id)->where('status', 'active')
            ->with('medicine')->get()->sortBy(fn ($p) => $p->medicine->brand_name.' '.$p->medicine->strength)->values();
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
        $canInvoice = auth()->user()->hasPermission('purchase.invoice');

        return view('livewire.purchasing.receipt-editor', [
            'canCreateProduct' => auth()->user()->hasPermission('catalogue.manage'),
            'canInvoice' => $canInvoice,
            'invoice' => $canInvoice && $this->receipt?->status === 'validated'
                ? app(PurchaseInvoiceService::class)->invoiceFor($this->receipt) : null,
        ]);
    }
}
