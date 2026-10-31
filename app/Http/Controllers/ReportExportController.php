<?php

namespace App\Http\Controllers;

use App\Livewire\Reports\ReportIndex;
use App\Models\AuditLog;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV d'un tableau de rapport : séparateur « ; », décimales à virgule et BOM UTF-8,
 * pour une ouverture directe dans Excel en français.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('report.export'), 403);
        $report = (string) $request->query('report');
        abort_unless(isset(ReportService::REPORTS[$report]), 404);

        $filters = ReportIndex::filters($request->query());
        $table = $reports->build($report, $user->pharmacy_id, $filters)[(int) $request->query('table', 0)] ?? abort(404);

        AuditLog::create([
            'pharmacy_id' => $user->pharmacy_id,
            'user_id' => $user->id,
            'action' => 'report.exported',
            'auditable_type' => 'report',
            'auditable_id' => $report,
            'new_values' => ['table' => $table['title'], 'from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString(), 'rows' => count($table['rows'])],
        ]);

        $name = 'pharmapos-'.$report.'-'.str($table['title'])->slug().'-'.$filters['from']->format('Ymd').'-'.$filters['to']->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($table) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, array_values($table['columns']), ';');
            foreach ([...$table['rows'], ...($table['totals'] ? [$table['totals']] : [])] as $row) {
                fputcsv($out, array_map(fn ($key) => $this->cell($row[$key] ?? ''), array_keys($table['columns'])), ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function cell($value): string
    {
        if (is_float($value)) {
            return number_format($value, 2, ',', '');
        }
        $value = (string) ($value ?? '');

        // Neutralise les formules (injection CSV)
        return preg_match('/^[=+\-@\t\r]/', $value) && ! is_numeric($value) ? "'".$value : $value;
    }
}
