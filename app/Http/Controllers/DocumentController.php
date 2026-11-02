<?php

namespace App\Http\Controllers;

use App\Livewire\Reports\ReportIndex;
use App\Models\CashSession;
use App\Services\Cash\CashSessionService;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
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
