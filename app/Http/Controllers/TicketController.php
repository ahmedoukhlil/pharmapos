<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ticket de caisse imprimable 58/80 mm (POS-08). Les mentions légales sont à valider (Annexe A).
 */
class TicketController extends Controller
{
    public function show(Request $request, Sale $sale): View
    {
        abort_unless($sale->pharmacy_id === $request->user()->pharmacy_id && $sale->status === 'finalized', 404);

        $sale->load(['pharmacy', 'register', 'user', 'items.presentation.medicine', 'items.batches.batch', 'payments.method']);

        return view('tickets.show', [
            'sale' => $sale,
            'width' => $sale->register->printer_width,
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /** Avoir imprimable remis au client lors d'un retour. */
    public function showReturn(Request $request, SaleReturn $saleReturn): View
    {
        abort_unless($saleReturn->pharmacy_id === $request->user()->pharmacy_id, 404);

        $saleReturn->load(['pharmacy', 'sale', 'user', 'cashSession.register', 'items.saleItemBatch.batch', 'items.saleItemBatch.saleItem.presentation.medicine']);

        return view('returns.show', [
            'return' => $saleReturn,
            'refunds' => Payment::with('method')->where('idempotency_key', $saleReturn->idempotency_key.':refund')->get(),
            'width' => $saleReturn->cashSession->register->printer_width,
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /** Bon de commande A4 à remettre ou envoyer au fournisseur. */
    public function showOrder(Request $request, PurchaseOrder $order): View
    {
        abort_unless($request->user()->hasPermission('purchase.manage'), 403);
        abort_unless($order->pharmacy_id === $request->user()->pharmacy_id && $order->status !== 'draft', 404);

        $order->load(['pharmacy', 'supplier', 'creator', 'lines.presentation.medicine', 'lines.presentation.barcodes']);

        return view('orders.print', ['order' => $order]);
    }
}
