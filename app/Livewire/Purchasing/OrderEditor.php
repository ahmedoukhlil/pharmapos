<?php

namespace App\Livewire\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\Presentation;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderException;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\ReceiptException;
use App\Support\ProductLookup;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Commande fournisseur - PharmaPOS')]
class OrderEditor extends Component
{
    public ?string $orderId = null;

    public string $supplierId = '';

    public string $expectedAt = '';

    public string $notes = '';

    public string $search = '';

    /** @var array<int, array{presentation_id:string, quantity_packs:string, unit_price:string}> */
    public array $lines = [];

    public ?string $error = null;

    public ?string $message = null;

    public function mount(?PurchaseOrder $order = null): void
    {
        abort_unless(auth()->user()->hasPermission('purchase.manage'), 403);

        if ($order?->exists) {
            abort_unless($order->pharmacy_id === auth()->user()->pharmacy_id, 404);
            $this->orderId = $order->id;
            $this->supplierId = $order->supplier_id;
            $this->expectedAt = (string) $order->expected_at?->toDateString();
            $this->notes = (string) $order->notes;
            $this->lines = $order->lines()->get()->map(fn ($l) => [
                'presentation_id' => $l->presentation_id,
                'quantity_packs' => (string) $l->quantity_packs,
                'unit_price' => (string) $l->unit_price,
            ])->all();

            return;
        }

        $this->supplierId = (string) $this->suppliers->first()?->id;
    }

    #[Computed]
    public function order(): ?PurchaseOrder
    {
        return $this->orderId ? PurchaseOrder::with(['lines', 'supplier'])->find($this->orderId) : null;
    }

    #[Computed]
    public function editable(): bool
    {
        return ! $this->order || $this->order->status === 'draft';
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::where('pharmacy_id', auth()->user()->pharmacy_id)->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function presentations(): Collection
    {
        return Presentation::with('medicine')->whereIn('id', array_column($this->lines, 'presentation_id'))->get()->keyBy('id');
    }

    #[Computed]
    public function results(): Collection
    {
        return strlen(trim($this->search)) >= 2 ? ProductLookup::find(auth()->user()->pharmacy_id, $this->search) : collect();
    }

    #[Computed]
    public function receipts(): Collection
    {
        return $this->orderId ? GoodsReceipt::where('purchase_order_id', $this->orderId)->latest()->get() : collect();
    }

    /** Le terme vient du client : le scanner envoie Entrée avant la synchronisation. */
    public function addFromSearch(?string $term = null): void
    {
        $this->search = $term ?? $this->search;
        unset($this->results);
        if ($this->results->count() === 1) {
            $this->add($this->results->first()->id);
        }
    }

    public function add(string $presentationId): void
    {
        $this->search = '';
        foreach ($this->lines as $i => $line) {
            if ($line['presentation_id'] === $presentationId) {
                $this->lines[$i]['quantity_packs'] = (string) ((int) $line['quantity_packs'] + 1);

                return;
            }
        }
        $p = Presentation::where('pharmacy_id', auth()->user()->pharmacy_id)->findOrFail($presentationId);
        $this->lines[] = ['presentation_id' => $p->id, 'quantity_packs' => '1', 'unit_price' => (string) $p->purchase_price];
        unset($this->presentations);
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    /** Ajoute les produits sous leur seuil qui ne sont pas déjà dans la commande. */
    public function suggest(PurchaseOrderService $service): void
    {
        $present = array_column($this->lines, 'presentation_id');
        $added = 0;
        foreach ($service->suggestions(auth()->user()) as $line) {
            if (! in_array($line['presentation_id'], $present, true)) {
                $this->lines[] = $line;
                $added++;
            }
        }
        unset($this->presentations);
        $this->message = $added ? "$added produit(s) sous le seuil ajouté(s)." : 'Aucun autre produit sous son seuil.';
    }

    public function save(PurchaseOrderService $service): ?PurchaseOrder
    {
        return $this->attempt(function () use ($service) {
            $order = $service->save(auth()->user(), [
                'supplier_id' => $this->supplierId,
                'expected_at' => $this->expectedAt,
                'notes' => $this->notes,
                'lines' => $this->lines,
            ], $this->order);
            $this->message = "Commande {$order->number} enregistrée.";
            if (! $this->orderId) {
                $this->orderId = $order->id;
                $this->js("history.replaceState(null, '', '".route('orders.edit', $order)."')");
            }

            return $order;
        });
    }

    public function send(PurchaseOrderService $service): void
    {
        $this->attempt(function () use ($service) {
            $order = $this->save($service) ?? throw new PurchaseOrderException('save_failed', (string) $this->error);
            $service->send(auth()->user(), $order);
            $this->message = "Commande {$order->number} envoyée.";
        });
    }

    public function cancel(PurchaseOrderService $service): void
    {
        $this->attempt(fn () => $service->cancel(auth()->user(), $this->order) && ($this->message = 'Commande annulée.'));
    }

    public function closeRemainder(PurchaseOrderService $service): void
    {
        $this->attempt(fn () => $service->closeRemainder(auth()->user(), $this->order) && ($this->message = 'Reliquat soldé : commande clôturée.'));
    }

    public function receive(PurchaseOrderService $service)
    {
        return $this->attempt(fn () => $this->redirectRoute('receipts.edit', $service->startReceipt(auth()->user(), $this->order)));
    }

    private function attempt(callable $fn)
    {
        $this->error = null;
        try {
            return $fn();
        } catch (PurchaseOrderException|ReceiptException $e) {
            $this->error = $e->getMessage();
            $this->message = null;

            return null;
        } finally {
            unset($this->order, $this->editable, $this->receipts);
        }
    }

    public function render()
    {
        return view('livewire.purchasing.order-editor');
    }
}
