<?php

namespace App\Livewire\Pos;

use App\Livewire\Concerns\RequiresReauth;
use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\Sales\SaleException;
use App\Services\Sales\SaleReturnService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Retour client sur ticket (POS-09) : quantités par lot, destination du produit, remboursement, avoir.
 * Les quantités sont saisies dans l'unité de vente du ticket (boîte ou unité).
 */
#[Layout('components.layouts.app')]
#[Title('Retours - PharmaPOS')]
class Returns extends Component
{
    use RequiresReauth;
    use WithPagination;

    /** Liste des reçus : toutes les caisses, ou seulement celle-ci. */
    public bool $allRegisters = true;

    #[Url(as: 'caisse')]
    public string $registerCode = '';

    public string $ticket = '';

    public ?string $saleId = null;

    /** @var array<string, string> sale_item_batch_id => quantité en unité de vente */
    public array $quantities = [];

    /** @var array<string, string> sale_item_batch_id => quarantine|restock_approved|destroy */
    public array $dispositions = [];

    public string $paymentMethodId = '';

    public string $reason = '';

    public string $reference = '';

    public string $idempotencyKey = '';

    public ?string $lastReturnId = null;

    public ?string $error = null;

    public function mount(): void
    {
        if (! $this->register) {
            $this->registerCode = Register::where('pharmacy_id', auth()->user()->pharmacy_id)->orderBy('code')->value('code') ?? '';
            unset($this->register);
        }
        $this->paymentMethodId = (string) $this->paymentMethods->firstWhere('is_cash', true)?->id;
        $this->idempotencyKey = (string) Str::uuid();
    }

    #[Computed]
    public function register(): ?Register
    {
        return Register::where('pharmacy_id', auth()->user()->pharmacy_id)->where('code', $this->registerCode)->first();
    }

    #[Computed]
    public function session(): ?CashSession
    {
        return $this->register
            ? CashSession::where('register_id', $this->register->id)->where('status', 'open')->latest('opened_at')->first()
            : null;
    }

    #[Computed]
    public function paymentMethods(): Collection
    {
        return PaymentMethod::where('pharmacy_id', auth()->user()->pharmacy_id)->where('is_active', true)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function sale(): ?Sale
    {
        return $this->saleId
            ? Sale::with(['items.presentation.medicine', 'items.batches.batch', 'returns'])->find($this->saleId)
            : null;
    }

    /** La recherche filtre la liste ; Entrée ouvre le reçu exact, ou le seul reçu trouvé. */
    public function updatedTicket(): void
    {
        $this->error = null;
        $this->resetPage();
    }

    public function updatedAllRegisters(): void
    {
        $this->resetPage();
    }

    public function open(string $saleId): void
    {
        $sale = Sale::where('pharmacy_id', auth()->user()->pharmacy_id)->where('status', 'finalized')->findOrFail($saleId);
        $this->ticket = $sale->ticket_number;
        $this->findSale();
    }

    public function backToList(): void
    {
        $this->reset('ticket', 'saleId', 'quantities', 'dispositions', 'error');
        unset($this->sale);
    }

    /** Reçus encaissés, les plus récents d'abord ; recherche par numéro, produit (nom ou DCI) ou caissier. */
    private function receipts()
    {
        $term = trim($this->ticket);

        return Sale::where('pharmacy_id', auth()->user()->pharmacy_id)->where('status', 'finalized')
            ->when(! $this->allRegisters && $this->register, fn ($q) => $q->where('register_id', $this->register->id))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('ticket_number', 'like', "%$term%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%$term%"))
                ->orWhereHas('items.presentation.medicine', fn ($m) => $m->where('brand_name', 'like', "%$term%")->orWhere('inn', 'like', "%$term%"))))
            ->with(['user:id,name', 'register:id,code', 'items.presentation.medicine:id,brand_name,strength'])->withCount('returns')
            ->latest('finalized_at')->paginate(15);
    }

    public function findSale(): void
    {
        $this->reset('error', 'saleId', 'quantities', 'dispositions', 'lastReturnId');
        $sale = Sale::where('pharmacy_id', auth()->user()->pharmacy_id)
            ->where('ticket_number', trim($this->ticket))
            ->where('status', 'finalized')
            ->first();
        if (! $sale && trim($this->ticket) !== '' && ($matches = $this->receipts())->total() === 1) {
            $sale = $matches->first();   // un seul reçu correspond à la recherche : on l'ouvre
        }

        if (! $sale) {
            $this->error = "Aucun reçu encaissé « {$this->ticket} ».";

            return;
        }

        $this->saleId = $sale->id;
        foreach ($sale->items as $item) {
            foreach ($item->batches as $allocation) {
                $this->quantities[$allocation->id] = '0';
                $this->dispositions[$allocation->id] = 'quarantine';
            }
        }
        unset($this->sale);
    }

    /**
     * Lignes retournables : une par lot vendu, avec quantité restante et montant prévisionnel.
     */
    #[Computed]
    public function lines(): array
    {
        if (! $this->sale) {
            return [];
        }
        $returned = app(SaleReturnService::class)->returnedQuantities($this->sale);
        $lines = [];

        foreach ($this->sale->items as $item) {
            $factor = $item->sale_unit === 'pack' ? $item->presentation->units_per_pack : 1;
            foreach ($item->batches as $allocation) {
                $remainingUnits = $allocation->quantity_base_units - ($returned[$allocation->id] ?? 0);
                $qty = max(0, (int) ($this->quantities[$allocation->id] ?? 0));
                $lines[] = [
                    'id' => $allocation->id,
                    'label' => trim($item->presentation->medicine->brand_name.' '.$item->presentation->medicine->strength),
                    'unit' => $item->sale_unit === 'pack' ? 'boîte(s)' : $item->presentation->base_unit,
                    'lot' => $allocation->batch->lot_number,
                    'expiry' => $allocation->batch->expiry_date->format('m/Y'),
                    'factor' => $factor,
                    'sold' => intdiv($allocation->quantity_base_units, $factor),
                    'remaining' => intdiv($remainingUnits, $factor),
                    'amount' => (int) round((float) $item->line_total * 100 * $qty * $factor / $item->quantity_base_units),
                ];
            }
        }

        return $lines;
    }

    public function submit(SaleReturnService $service): void
    {
        if (! $this->reauthenticated('sale.refund', 'submit')) {
            return;
        }
        $this->error = null;
        if (! $this->session) {
            $this->error = 'Ouvrez une session de caisse pour rembourser.';

            return;
        }

        $factors = array_column($this->lines, 'factor', 'id');
        try {
            $return = $service->process(auth()->user(), $this->session, $this->sale, [
                'idempotency_key' => $this->idempotencyKey,
                'payment_method_id' => $this->paymentMethodId,
                'reason' => $this->reason,
                'reference' => $this->reference !== '' ? $this->reference : null,
                'items' => collect($this->quantities)->map(fn ($qty, $id) => [
                    'sale_item_batch_id' => $id,
                    'quantity' => max(0, (int) $qty) * ($factors[$id] ?? 1),
                    'disposition' => $this->dispositions[$id] ?? 'quarantine',
                ])->values()->all(),
            ]);
        } catch (SaleException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->lastReturnId = $return->id;
        $this->reset('ticket', 'saleId', 'quantities', 'dispositions', 'reason', 'reference');
        $this->idempotencyKey = (string) Str::uuid();
        unset($this->sale, $this->lines);
        $this->dispatch('return-processed', url: route('returns.show', ['saleReturn' => $return->id, 'print' => 1]));
    }

    #[Computed]
    public function lastReturn(): ?SaleReturn
    {
        return $this->lastReturnId ? SaleReturn::find($this->lastReturnId) : null;
    }

    public function render()
    {
        return view('livewire.pos.returns', [
            'receipts' => $this->saleId ? null : $this->receipts(),
            'canDispose' => auth()->user()->hasPermission('batch.quarantine'),
            'canRefund' => auth()->user()->hasPermission('sale.refund'),
        ]);
    }
}
