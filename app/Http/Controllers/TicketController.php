<?php

namespace App\Http\Controllers;

use App\Models\Sale;
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
}
