<?php

namespace App\Livewire\Pos;

use App\Livewire\Concerns\RequiresReauth;
use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\Presentation;
use App\Models\Register;
use App\Models\Sale;
use App\Models\StockReservation;
use App\Services\Sales\FefoAllocator;
use App\Services\Sales\SaleException;
use App\Services\Sales\SaleService;
use App\Support\PharmacySettings;
use App\Support\ProductLookup;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Écran de caisse (PRD §7) : recherche/scan, panier, lot FEFO proposé, paiements, validation.
 * Les montants affichés sont indicatifs ; SaleService recalcule et contrôle tout côté serveur.
 */
#[Layout('components.layouts.app')]
#[Title('Caisse — PharmaPOS')]
class Checkout extends Component
{
    use RequiresReauth;

    #[Url(as: 'caisse')]
    public string $registerCode = '';

    public string $openingFloat = '0';

    public string $search = '';

    /** @var array<int, array{presentation_id: string, quantity: int, sale_unit: string, batch_id: ?string, discount_amount: string}> */
    public array $cart = [];

    /** @var array<int, array{payment_method_id: string, amount: string, reference: string}> */
    public array $payments = [];

    public string $idempotencyKey = '';

    public ?string $lastSaleId = null;

    /** Vente en attente reprise dans le panier ; finalisée ou remise en attente sur le même enregistrement. */
    public ?string $heldSaleId = null;

    public ?string $error = null;

    public function mount(): void
    {
        $registers = $this->registers;
        if (! $registers->firstWhere('code', $this->registerCode)) {
            $this->registerCode = $registers->first()?->code ?? '';
        }
        $this->idempotencyKey = (string) Str::uuid();
    }

    #[Computed]
    public function registers(): Collection
    {
        return Register::where('pharmacy_id', auth()->user()->pharmacy_id)->where('is_active', true)->orderBy('code')->get();
    }

    #[Computed]
    public function register(): ?Register
    {
        return $this->registers->firstWhere('code', $this->registerCode);
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
    public function can(): array
    {
        $user = auth()->user();

        return [
            'open' => $user->hasPermission('cash.open_close'),
            'sell' => $user->hasPermission('sale.create'),
            'discount' => $user->hasPermission('sale.discount'),
            'override' => $user->hasPermission('sale.fefo_override'),
        ];
    }

    public function openSession(): void
    {
        $this->validate(['openingFloat' => ['required', 'numeric', 'min:0']], [], ['openingFloat' => 'fonds de caisse']);
        abort_unless($this->can['open'] && $this->register, 403);

        if (! $this->session) {
            CashSession::create([
                'pharmacy_id' => $this->register->pharmacy_id,
                'register_id' => $this->register->id,
                'opened_by' => auth()->id(),
                'opened_at' => now(),
                'opening_float' => $this->openingFloat,
            ]);
        }
        unset($this->session);
    }

    /**
     * Résultats de recherche : code-barres et SKU exacts d'abord, puis DCI / nom commercial.
     */
    #[Computed]
    public function results(): Collection
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        return $this->lookup($term)->take(8);
    }

    /** Produits trouvés par nom ou DCI mais invendables faute de présentation. */
    #[Computed]
    public function withoutPresentation(): Collection
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        return \App\Models\Medicine::where('pharmacy_id', auth()->user()->pharmacy_id)
            ->where(fn ($q) => $q->where('brand_name', 'like', "%$term%")->orWhere('inn', 'like', "%$term%"))
            ->whereDoesntHave('presentations')->limit(3)->get();
    }

    private function lookup(string $term): Collection
    {
        return ProductLookup::find(auth()->user()->pharmacy_id, $term);
    }

    /**
     * Entrée dans le champ de recherche : un scan ajoute directement le produit identifié.
     * La valeur est passée par le client car un lecteur USB envoie Entrée avant la synchro debounce.
     */
    public function addFromSearch(?string $term = null): void
    {
        $term = trim($term ?? $this->search);
        $this->search = '';
        $this->error = null;   // le scanner caméra lit l'erreur de CE code, pas celle du précédent
        if ($term === '') {
            return;
        }
        $match = $this->lookup($term);
        if ($match->count() === 1 || ($match->isNotEmpty() && $match->first()->sku === $term)) {
            $this->add($match->first()->id);
        } elseif ($match->isEmpty()) {
            $this->error = "Aucun produit pour « $term ».";
        } else {
            // Plusieurs résultats : on garde la saisie pour laisser choisir dans la liste
            $this->search = $term;
        }
    }

    /** Scan reçu du smartphone associé (ScannerPanel). */
    #[On('remote-scan')]
    public function addRemote(string $presentationId): void
    {
        abort_unless(Presentation::where('pharmacy_id', auth()->user()->pharmacy_id)->whereKey($presentationId)->exists(), 404);
        $this->add($presentationId);
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

    public function add(string $presentationId): void
    {
        $this->error = null;
        $this->lastSaleId = null;

        // Un scan répété d'un même produit augmente la quantité (SCN-04)
        foreach ($this->cart as $i => $line) {
            if ($line['presentation_id'] === $presentationId && $line['sale_unit'] === 'pack' && empty($line['batch_id'])) {
                $this->cart[$i]['quantity']++;
                $this->search = '';

                return;
            }
        }

        $this->cart[] = [
            'presentation_id' => $presentationId,
            'quantity' => 1,
            'sale_unit' => 'pack',
            'batch_id' => null,
            'discount_amount' => '0',
        ];
        $this->search = '';
    }

    public function increment(int $i, int $delta): void
    {
        if (! isset($this->cart[$i])) {
            return;
        }
        $this->cart[$i]['quantity'] = max(1, (int) $this->cart[$i]['quantity'] + $delta);
    }

    public function remove(int $i): void
    {
        unset($this->cart[$i]);
        $this->cart = array_values($this->cart);
    }

    /** Ajoute un paiement pour le reste dû (ou le total si aucun paiement). */
    public function addPayment(string $methodId): void
    {
        $remaining = max(0, $this->totals['total'] - $this->totals['paid']);
        $this->payments[] = [
            'payment_method_id' => $methodId,
            'amount' => number_format($remaining / 100, 2, '.', ''),
            'reference' => '',
        ];
    }

    public function removePayment(int $i): void
    {
        unset($this->payments[$i]);
        $this->payments = array_values($this->payments);
    }

    /**
     * Lignes du panier enrichies pour l'affichage : prix, lot FEFO proposé, lots sélectionnables.
     */
    #[Computed]
    public function lines(): array
    {
        if (! $this->cart) {
            return [];
        }

        $presentations = Presentation::with('medicine')->whereIn('id', array_column($this->cart, 'presentation_id'))->get()->keyBy('id');
        $balances = app(FefoAllocator::class)
            ->sellableBalances(auth()->user()->pharmacy_id, $presentations->keys()->all())
            ->groupBy('presentation_id');

        // Le stock réservé par la vente reprise lui reste disponible
        $ownReserved = $this->heldSaleId
            ? StockReservation::where('sale_id', $this->heldSaleId)->whereNull('released_at')
                ->join('stock_balances', 'stock_balances.id', '=', 'stock_reservations.stock_balance_id')
                ->groupBy('stock_balances.presentation_id')
                ->selectRaw('stock_balances.presentation_id, SUM(stock_reservations.quantity) as qty')
                ->pluck('qty', 'presentation_id')
            : collect();

        return collect($this->cart)->map(function ($line, $i) use ($presentations, $balances, $ownReserved) {
            $p = $presentations[$line['presentation_id']];
            $candidates = $balances->get($p->id, collect());
            $isUnit = $line['sale_unit'] === 'unit';
            $unitPrice = (float) ($isUnit ? $p->unit_sale_price : $p->sale_price);
            $gross = (int) round($line['quantity'] * $unitPrice * 100);
            $available = $candidates->sum(fn ($b) => $b->quantity - $b->reserved_quantity) + (int) ($ownReserved[$p->id] ?? 0);
            $needed = $line['quantity'] * ($isUnit ? 1 : $p->units_per_pack);

            return [
                'index' => $i,
                'label' => trim($p->medicine->brand_name.' '.$p->medicine->strength),
                'presentation' => $p->label,
                'sku' => $p->sku,
                'rx' => $p->medicine->prescription_required,
                'can_split' => $p->allow_split_sale && $p->unit_sale_price !== null,
                'unit_price' => $unitPrice,
                'gross' => $gross,
                'total' => max(0, $gross - (int) round((float) $line['discount_amount'] * 100)),
                'fefo' => $candidates->first()?->batch,
                'batches' => $candidates->pluck('batch')->unique('id')->values(),
                'available' => $available,
                'short' => $needed > $available,
            ];
        })->all();
    }

    #[Computed]
    public function totals(): array
    {
        $raw = array_sum(array_column($this->lines, 'total'));
        $step = $this->register
            ? (int) round((float) PharmacySettings::get($this->register->pharmacy_id, 'sale.rounding_step', 1) * 100)
            : 100;
        $total = $step > 0 ? (int) (round($raw / $step) * $step) : $raw;
        $paid = (int) array_sum(array_map(fn ($p) => (int) round((float) $p['amount'] * 100), $this->payments));

        return ['raw' => $raw, 'total' => $total, 'paid' => $paid, 'change' => max(0, $paid - $total), 'due' => max(0, $total - $paid)];
    }

    public function checkout(SaleService $service): void
    {
        $this->error = null;
        if (! $this->session) {
            $this->error = "Aucune session de caisse ouverte.";

            return;
        }

        // Remise ou lot choisi hors FEFO : réauthentification avant l'encaissement
        $discounted = $this->can['discount'] && collect($this->cart)->contains(fn ($l) => (float) $l['discount_amount'] > 0);
        $override = collect($this->cart)->contains(fn ($l) => ! empty($l['batch_id']));
        if (($discounted && ! $this->reauthenticated('sale.discount', 'checkout'))
            || ($override && ! $this->reauthenticated('sale.fefo_override', 'checkout'))) {
            return;
        }

        if (! $this->payments && $cash = $this->paymentMethods->firstWhere('is_cash', true)) {
            $this->addPayment($cash->id);
        }

        try {
            $sale = $service->finalize(auth()->user(), $this->session, [
                'idempotency_key' => $this->idempotencyKey,
                'items' => array_map(fn ($l) => [
                    'presentation_id' => $l['presentation_id'],
                    'quantity' => (int) $l['quantity'],
                    'sale_unit' => $l['sale_unit'],
                    'batch_id' => $l['batch_id'] ?: null,
                    'discount_amount' => $this->can['discount'] ? $l['discount_amount'] : 0,
                ], $this->cart),
                'payments' => array_map(fn ($p) => [
                    'payment_method_id' => $p['payment_method_id'],
                    'amount' => $p['amount'],
                    'reference' => $p['reference'] !== '' ? $p['reference'] : null,
                ], $this->payments),
                'held_sale_id' => $this->heldSaleId,
            ]);
        } catch (SaleException $e) {
            $line = $e->context['line'] ?? null;
            $this->error = $e->getMessage().($line ? " (ligne $line)" : '');

            return;
        }

        $this->clear();
        $this->lastSaleId = $sale->id;
        $this->dispatch('sale-finalized', url: route('tickets.show', ['sale' => $sale->id, 'print' => 1]));
    }

    /** Met le panier en attente (stock réservé) pour servir un autre client. */
    public function hold(SaleService $service): void
    {
        $this->error = null;
        if (! $this->session || ! $this->cart) {
            return;
        }
        try {
            $service->hold(auth()->user(), $this->session, [
                'items' => array_map(fn ($l) => [
                    'presentation_id' => $l['presentation_id'],
                    'quantity' => (int) $l['quantity'],
                    'sale_unit' => $l['sale_unit'],
                    'batch_id' => $l['batch_id'] ?: null,
                    'discount_amount' => $this->can['discount'] ? $l['discount_amount'] : 0,
                ], $this->cart),
                'held_sale_id' => $this->heldSaleId,
            ]);
        } catch (SaleException $e) {
            $line = $e->context['line'] ?? null;
            $this->error = $e->getMessage().($line ? " (ligne $line)" : '');

            return;
        }
        $this->clear();
    }

    public function resume(string $saleId, SaleService $service): void
    {
        $this->error = null;
        if ($this->cart) {
            $this->error = "Mettez d'abord le panier en cours en attente ou videz-le.";

            return;
        }
        $sale = $this->heldSales->firstWhere('id', $saleId);
        if (! $sale) {
            $this->error = "Cette vente n'est plus en attente.";

            return;
        }
        $this->cart = $service->heldItems($sale);
        $this->heldSaleId = $sale->id;
        $this->lastSaleId = null;
    }

    public function cancelHeld(string $saleId, SaleService $service): void
    {
        $this->error = null;
        $sale = $this->heldSales->firstWhere('id', $saleId);
        if (! $sale) {
            return;
        }
        try {
            $service->cancelHeld(auth()->user(), $sale, 'Annulée depuis la caisse');
        } catch (SaleException $e) {
            $this->error = $e->getMessage();

            return;
        }
        if ($this->heldSaleId === $saleId) {
            $this->clear();
        }
        unset($this->heldSales);
    }

    /** Ventes en attente sur la session ouverte de cette caisse, hors celle reprise dans le panier. */
    #[Computed]
    public function heldSales(): Collection
    {
        return $this->session
            ? Sale::with('user')->withCount('items')
                ->where('cash_session_id', $this->session->id)
                ->where('status', 'on_hold')
                ->when($this->heldSaleId, fn ($q) => $q->whereKeyNot($this->heldSaleId))
                ->orderBy('held_at')
                ->get()
            : collect();
    }

    public function clear(): void
    {
        $this->cart = [];
        $this->payments = [];
        $this->error = null;
        $this->heldSaleId = null;
        $this->idempotencyKey = (string) Str::uuid();
        // Les propriétés calculées sont mémorisées pour la requête : les invalider après avoir vidé le panier
        unset($this->heldSales, $this->lines, $this->totals);
    }

    #[Computed]
    public function lastSale(): ?Sale
    {
        return $this->lastSaleId ? Sale::find($this->lastSaleId) : null;
    }

    public function render()
    {
        return view('livewire.pos.checkout');
    }
}
