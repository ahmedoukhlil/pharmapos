<?php

namespace App\Http\Controllers;

use App\Livewire\Reports\ReportIndex;
use App\Models\CashSession;
use App\Models\Supplier;
use App\Services\Cash\CashSessionService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Documents A4 imprimables partageant la mise en page du bon de commande (x-document).
 */
class DocumentController extends Controller
{
    public function session(Request $request, CashSession $session, CashSessionService $service): View
    {
        abort_unless($session->pharmacy_id === $request->user()->pharmacy_id, 404);
        $session->load(['pharmacy', 'register', 'opener', 'closer', 'validator', 'movements.user']);

        return view('documents.session', ['s' => $session, 'summary' => $service->summary($session)]);
    }

    /** Relevé de compte : ?du=AAAA-MM-JJ&au=AAAA-MM-JJ (facultatifs). */
    public function supplierStatement(Request $request, Supplier $supplier, PurchaseInvoiceService $service): View
    {
        $user = $request->user();
        abort_unless($user->hasPermission('purchase.invoice'), 403);
        abort_unless($supplier->pharmacy_id === $user->pharmacy_id, 404);
        $date = fn ($k) => rescue(fn () => $request->query($k) ? Carbon::parse($request->query($k)) : null, null, false);
        $from = $date('du');
        $to = $date('au')?->endOfDay();

        return view('documents.supplier-statement', [
            'pharmacy' => $user->pharmacy,
            'supplier' => $supplier,
            'from' => $from,
            'to' => $to,
            'statement' => $service->statement($supplier, $from, $to),
            'advances' => (float) $service->unappliedAdvances($user->pharmacy_id, $supplier->id)->sum('amount'),
        ]);
    }

    public function report(Request $request, ReportService $reports): View
    {
        $user = $request->user();
        abort_unless($user->hasPermission('report.view'), 403);
        $report = (string) $request->query('report');
        abort_unless(isset(ReportService::REPORTS[$report]), 404);
        $filters = ReportIndex::filters($request->query());

        return view('documents.report', [
            'pharmacy' => $user->pharmacy,
            'title' => ReportService::REPORTS[$report],
            'periodic' => in_array($report, ['sales', 'expiry', 'cash'], true),
            'filters' => $filters,
            'tables' => $reports->build($report, $user->pharmacy_id, $filters),
            'author' => $user->name,
        ]);
    }
}
