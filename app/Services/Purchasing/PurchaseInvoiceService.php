<?php

namespace App\Services\Purchasing;

use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ACH-05 : factures fournisseurs, dettes et règlements (partiels) ; ACH-06 : rapprochement
 * commande / réception / facture au niveau des lignes. Montants comparés en centimes.
 */
class PurchaseInvoiceService
{
    public const PAYMENT_METHODS = [
        'transfer' => 'Virement', 'cheque' => 'Chèque', 'cash' => 'Espèces', 'bankily' => 'Bankily', 'masrvi' => 'Masrvi',
    ];

    /**
     * Données : supplier_id, supplier_invoice_number, invoice_date, due_date?, total_amount, receipt_ids[].
     * L'échéance par défaut découle du délai de paiement du fournisseur.
     */
    public function create(User $user, array $data): PurchaseInvoice
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $data) {
            $pharmacyId = $user->pharmacy_id;
            $supplier = Supplier::where('pharmacy_id', $pharmacyId)->find($data['supplier_id'] ?? null)
                ?? throw new PurchaseOrderException('invalid_supplier', 'Fournisseur invalide.');

            $number = trim((string) ($data['supplier_invoice_number'] ?? ''));
            if ($number === '') {
                throw new PurchaseOrderException('number_required', 'Numéro de facture du fournisseur obligatoire.');
            }
            if (PurchaseInvoice::where('pharmacy_id', $pharmacyId)->where('supplier_id', $supplier->id)->where('supplier_invoice_number', $number)->exists()) {
                throw new PurchaseOrderException('duplicate_invoice', "La facture $number de ce fournisseur est déjà enregistrée.");
            }

            $total = $this->cents($data['total_amount'] ?? null);
            if ($total === null || $total <= 0) {
                throw new PurchaseOrderException('invalid_amount', 'Montant de la facture invalide.');
            }

            $invoiceDate = $this->date($data['invoice_date'] ?? null) ?? throw new PurchaseOrderException('invalid_date', 'Date de facture invalide.');
            if ($invoiceDate->isFuture()) {
                throw new PurchaseOrderException('invalid_date', 'La date de facture ne peut pas être dans le futur.');
            }
            $dueDate = $this->date($data['due_date'] ?? null) ?? $invoiceDate->copy()->addDays($supplier->payment_terms_days);

            $receiptIds = array_values(array_unique($data['receipt_ids'] ?? []));
            $receipts = GoodsReceipt::where('pharmacy_id', $pharmacyId)->whereIn('id', $receiptIds)->lockForUpdate()->get();
            foreach ($receipts as $r) {
                if ($r->supplier_id !== $supplier->id || $r->status !== 'validated') {
                    throw new PurchaseOrderException('invalid_receipt', "La réception {$r->number} n'est pas une réception validée de ce fournisseur.");
                }
            }
            if ($receipts->count() !== count($receiptIds)) {
                throw new PurchaseOrderException('invalid_receipt', 'Réception introuvable.');
            }
            $already = DB::table('purchase_invoice_receipts')
                ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_receipts.purchase_invoice_id')
                ->whereIn('goods_receipt_id', $receiptIds)->where('purchase_invoices.status', '!=', 'cancelled')->exists();
            if ($already) {
                throw new PurchaseOrderException('receipt_already_invoiced', 'Une des réceptions est déjà rattachée à une autre facture.');
            }

            $invoice = PurchaseInvoice::create([
                'pharmacy_id' => $pharmacyId,
                'supplier_id' => $supplier->id,
                'supplier_invoice_number' => $number,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'total_amount' => $total / 100,
                'paid_amount' => 0,
                'status' => 'open',
            ]);
            $invoice->goodsReceipts()->attach($receiptIds);

            $this->audit($user, $invoice, 'purchase_invoice.created', [
                'number' => $number, 'total' => $total / 100, 'difference' => $this->reconcile($invoice)['difference'],
            ]);

            return $invoice;
        });
    }

    /**
     * Rapprochement ligne à ligne : quantités commandées / reçues, prix commande / réception,
     * puis écart global entre la facture et la valeur des quantités acceptées.
     */
    public function reconcile(PurchaseInvoice $invoice): array
    {
        $receipts = $invoice->goodsReceipts()->with(['lines.presentation.medicine', 'lines.purchaseOrderLine', 'purchaseOrder'])->orderBy('number')->get();

        $lines = $receipts->flatMap(fn ($r) => $r->lines->sortBy('line_no')->map(function ($l) use ($r) {
            $po = $l->purchaseOrderLine;
            $accepted = $l->decision !== 'refused';
            $anomalies = [];
            if (! $accepted) {
                $anomalies[] = 'Refusé à la réception';
            }
            if ($po && $this->cents($po->unit_price) !== $this->cents($l->unit_price)) {
                $anomalies[] = 'Prix différent de la commande ('.number_format((float) $po->unit_price, 2, ',', ' ').')';
            }
            if ($r->purchase_order_id && ! $po) {
                $anomalies[] = 'Hors commande';
            }

            return (object) [
                'receipt' => $r->number,
                'order' => $r->purchaseOrder?->number,
                'product' => trim($l->presentation->medicine->brand_name.' '.$l->presentation->medicine->strength),
                'sku' => $l->presentation->sku,
                'ordered' => $po?->quantity_packs,
                'received' => $l->quantity_packs,
                'invoiceable' => $accepted ? $l->quantity_packs : 0,
                'unit_price' => (string) $l->unit_price,
                'amount_cents' => $accepted ? $this->cents($l->unit_price) * $l->quantity_packs : 0,
                'anomalies' => $anomalies,
            ];
        }))->values();

        // Reliquats des commandes concernées encore non livrés
        $pending = $receipts->pluck('purchaseOrder')->filter()->unique('id')
            ->flatMap(fn ($o) => $o->lines()->whereColumn('received_packs', '<', 'quantity_packs')->get())
            ->sum(fn ($l) => $l->quantity_packs - $l->received_packs);

        $expected = $lines->sum('amount_cents');
        $difference = $this->cents($invoice->total_amount) - $expected;

        return [
            'lines' => $lines,
            'expected' => $expected / 100,
            'difference' => $difference / 100,
            'pending_packs' => $pending,
            'anomalies' => $lines->sum(fn ($l) => count($l->anomalies)),
            'matched' => $difference === 0 && $lines->every(fn ($l) => $l->anomalies === []),
        ];
    }

    /** Règlement total ou partiel ; jamais au-delà du solde restant. */
    public function pay(User $user, PurchaseInvoice $invoice, array $data): SupplierPayment
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $invoice, $data) {
            $invoice = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->pharmacy_id === $user->pharmacy_id, 404);
            if (! in_array($invoice->status, ['open', 'partially_paid'], true)) {
                throw new PurchaseOrderException('not_payable', 'Cette facture ne peut plus être réglée.');
            }

            [$amount, $method, $reference, $paidOn] = $this->paymentFields($data);
            $balance = $this->cents($invoice->total_amount) - $this->cents($invoice->paid_amount);
            if ($amount > $balance) {
                throw new PurchaseOrderException('overpayment', 'Le règlement dépasse le solde de la facture ('.number_format($balance / 100, 2, ',', ' ').' MRU).');
            }

            $payment = SupplierPayment::create([
                'pharmacy_id' => $invoice->pharmacy_id,
                'supplier_id' => $invoice->supplier_id,
                'purchase_invoice_id' => $invoice->id,
                'amount' => $amount / 100,
                'method' => $method,
                'reference' => $reference,
                'paid_on' => $paidOn,
                'recorded_by' => $user->id,
            ]);

            $this->addPaid($invoice, $amount);
            $this->audit($user, $invoice, 'supplier_payment.recorded', ['amount' => $amount / 100, 'method' => $method, 'reference' => $reference]);

            return $payment;
        });
    }

    /** Acompte : règlement versé au fournisseur avant ou sans facture, imputé plus tard. */
    public function advance(User $user, Supplier $supplier, array $data): SupplierPayment
    {
        $this->authorize($user);
        abort_unless($supplier->pharmacy_id === $user->pharmacy_id, 404);
        [$amount, $method, $reference, $paidOn] = $this->paymentFields($data);

        $payment = SupplierPayment::create([
            'pharmacy_id' => $supplier->pharmacy_id,
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => null,
            'amount' => $amount / 100,
            'method' => $method,
            'reference' => $reference,
            'paid_on' => $paidOn,
            'recorded_by' => $user->id,
        ]);
        AuditLog::create([
            'pharmacy_id' => $supplier->pharmacy_id, 'user_id' => $user->id, 'action' => 'supplier_payment.advance',
            'auditable_type' => 'supplier', 'auditable_id' => $supplier->id,
            'new_values' => ['amount' => $amount / 100, 'method' => $method, 'reference' => $reference],
        ]);

        return $payment;
    }

    /** Acomptes du fournisseur pas encore imputés sur une facture. */
    public function unappliedAdvances(string $pharmacyId, string $supplierId): Collection
    {
        return SupplierPayment::where('pharmacy_id', $pharmacyId)->where('supplier_id', $supplierId)
            ->whereNull('purchase_invoice_id')->orderBy('paid_on')->get();
    }

    /**
     * Impute un acompte sur une facture, dans la limite du solde ; le reliquat reste un acompte.
     */
    public function applyAdvance(User $user, PurchaseInvoice $invoice, SupplierPayment $advance): void
    {
        $this->authorize($user);

        DB::transaction(function () use ($user, $invoice, $advance) {
            $invoice = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $advance = SupplierPayment::whereKey($advance->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->pharmacy_id === $user->pharmacy_id && $advance->pharmacy_id === $user->pharmacy_id, 404);
            if ($advance->purchase_invoice_id !== null || $advance->supplier_id !== $invoice->supplier_id) {
                throw new PurchaseOrderException('invalid_advance', 'Cet acompte n\'est pas disponible pour ce fournisseur.');
            }
            if (! in_array($invoice->status, ['open', 'partially_paid'], true)) {
                throw new PurchaseOrderException('not_payable', 'Cette facture ne peut plus être réglée.');
            }

            $balance = $this->cents($invoice->total_amount) - $this->cents($invoice->paid_amount);
            $available = $this->cents($advance->amount);
            $used = min($balance, $available);
            if ($used === $available) {
                $advance->update(['purchase_invoice_id' => $invoice->id]);
            } else {
                // Acompte supérieur au solde : la part imputée devient un règlement, le reste demeure un acompte
                $advance->update(['amount' => ($available - $used) / 100]);
                $advance->replicate()->fill(['purchase_invoice_id' => $invoice->id, 'amount' => $used / 100])->save();
            }

            $this->addPaid($invoice, $used);
            $this->audit($user, $invoice, 'supplier_payment.advance_applied', ['amount' => $used / 100, 'reference' => $advance->reference]);
        });
    }

    /**
     * Relevé de compte : factures (débit) et règlements (crédit) avec solde cumulé.
     *
     * @return array{opening: float, entries: list<array>, closing: float}
     */
    public function statement(Supplier $supplier, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $invoices = PurchaseInvoice::where('supplier_id', $supplier->id)->where('status', '!=', 'cancelled')->get()
            ->map(fn ($i) => ['date' => $i->invoice_date, 'label' => 'Facture '.$i->supplier_invoice_number, 'due' => $i->due_date,
                'debit' => $this->cents($i->total_amount), 'credit' => 0]);
        $payments = SupplierPayment::with('invoice')->where('supplier_id', $supplier->id)->get()
            ->map(fn ($p) => ['date' => $p->paid_on, 'due' => null, 'debit' => 0, 'credit' => $this->cents($p->amount),
                'label' => ($p->purchase_invoice_id ? 'Règlement facture '.$p->invoice->supplier_invoice_number : 'Acompte')
                    .' - '.self::PAYMENT_METHODS[$p->method].($p->reference ? ' '.$p->reference : '')]);

        $all = $invoices->concat($payments)->sortBy(fn ($e) => $e['date']->format('Ymd').($e['debit'] ? '0' : '1'))->values();
        $opening = $all->filter(fn ($e) => $from && $e['date']->lt($from))->sum(fn ($e) => $e['debit'] - $e['credit']);
        $balance = $opening;
        $entries = [];
        foreach ($all as $e) {
            if (($from && $e['date']->lt($from)) || ($to && $e['date']->gt($to))) {
                continue;
            }
            $balance += $e['debit'] - $e['credit'];
            $entries[] = ['date' => $e['date'], 'label' => $e['label'], 'due' => $e['due'],
                'debit' => $e['debit'] / 100, 'credit' => $e['credit'] / 100, 'balance' => $balance / 100];
        }

        return ['opening' => $opening / 100, 'entries' => $entries, 'closing' => $balance / 100];
    }

    /** Facture active (non annulée) à laquelle la réception est rattachée. */
    public function invoiceFor(GoodsReceipt $receipt): ?PurchaseInvoice
    {
        return PurchaseInvoice::where('status', '!=', 'cancelled')
            ->whereHas('goodsReceipts', fn ($q) => $q->whereKey($receipt->id))->first();
    }

    /** @return array{int, string, ?string, Carbon} montant en centimes, mode, référence, date */
    private function paymentFields(array $data): array
    {
        $amount = $this->cents($data['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            throw new PurchaseOrderException('invalid_amount', 'Montant du règlement invalide.');
        }
        $method = $data['method'] ?? '';
        if (! isset(self::PAYMENT_METHODS[$method])) {
            throw new PurchaseOrderException('invalid_method', 'Mode de règlement invalide.');
        }
        $reference = trim((string) ($data['reference'] ?? '')) ?: null;
        if ($method !== 'cash' && $reference === null) {
            throw new PurchaseOrderException('reference_required', 'Référence obligatoire (n° de virement, de chèque ou de transaction).');
        }
        $paidOn = $this->date($data['paid_on'] ?? null) ?? Carbon::today();
        if ($paidOn->isFuture()) {
            throw new PurchaseOrderException('invalid_date', 'La date de règlement ne peut pas être dans le futur.');
        }

        return [$amount, $method, $reference, $paidOn];
    }

    private function addPaid(PurchaseInvoice $invoice, int $amount): void
    {
        $paid = $this->cents($invoice->paid_amount) + $amount;
        $invoice->update([
            'paid_amount' => $paid / 100,
            'status' => $paid === $this->cents($invoice->total_amount) ? 'paid' : 'partially_paid',
        ]);
    }

    /** Annulation d'une facture saisie par erreur, uniquement sans règlement ; ses réceptions redeviennent facturables. */
    public function cancel(User $user, PurchaseInvoice $invoice): void
    {
        $this->authorize($user);

        DB::transaction(function () use ($user, $invoice) {
            $invoice = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->pharmacy_id === $user->pharmacy_id, 404);
            if ($invoice->status !== 'open' || $this->cents($invoice->paid_amount) > 0) {
                throw new PurchaseOrderException('not_cancellable', 'Une facture déjà réglée (même partiellement) ne peut pas être annulée.');
            }
            $invoice->update(['status' => 'cancelled']);
            $this->audit($user, $invoice, 'purchase_invoice.cancelled', null);
        });
    }

    /** Réceptions validées du fournisseur pas encore rattachées à une facture active. */
    public function uninvoicedReceipts(string $pharmacyId, string $supplierId): Collection
    {
        return GoodsReceipt::with('purchaseOrder')
            ->where('pharmacy_id', $pharmacyId)->where('supplier_id', $supplierId)->where('status', 'validated')
            ->whereNotExists(fn ($q) => $q->from('purchase_invoice_receipts')
                ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_receipts.purchase_invoice_id')
                ->whereColumn('purchase_invoice_receipts.goods_receipt_id', 'goods_receipts.id')
                ->where('purchase_invoices.status', '!=', 'cancelled'))
            ->orderBy('validated_at')->get();
    }

    /** Valeur des quantités acceptées d'une réception (en MRU). */
    public function receiptValue(GoodsReceipt $receipt): float
    {
        return $receipt->lines()->where('decision', '!=', 'refused')->get()
            ->sum(fn ($l) => $this->cents($l->unit_price) * $l->quantity_packs) / 100;
    }

    private function authorize(User $user): void
    {
        if (! $user->hasPermission('purchase.invoice')) {
            throw new PurchaseOrderException('forbidden', "Vous n'êtes pas autorisé à gérer les factures fournisseurs.");
        }
    }

    private function audit(User $user, PurchaseInvoice $invoice, string $action, ?array $values): void
    {
        AuditLog::create([
            'pharmacy_id' => $invoice->pharmacy_id,
            'user_id' => $user->id,
            'action' => $action,
            'auditable_type' => 'purchase_invoice',
            'auditable_id' => $invoice->id,
            'new_values' => $values,
        ]);
    }

    private function cents($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = is_string($value) ? str_replace([' ', ','], ['', '.'], $value) : $value;

        return is_numeric($value) ? (int) round((float) $value * 100) : null;
    }

    private function date($value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
