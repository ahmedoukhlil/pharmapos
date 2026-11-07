<?php

namespace App\Livewire\Reports;

use App\Models\Location;
use App\Models\Register;
use App\Models\User;
use App\Services\Reports\DashboardService;
use App\Services\Reports\ReportService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Rapports - PharmaPOS')]
class ReportIndex extends Component
{
    #[Url]
    public string $report = 'dashboard';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** day | week | month */
    #[Url]
    public string $group = 'day';

    #[Url]
    public string $register = '';

    #[Url]
    public string $user = '';

    #[Url]
    public string $location = '';

    #[Url]
    public string $type = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('report.view'), 403);
        if ($this->report !== 'dashboard' && ! isset(ReportService::REPORTS[$this->report])) {
            $this->report = 'dashboard';
        }
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    /** Raccourcis de période. */
    public function period(string $preset): void
    {
        [$this->from, $this->to] = match ($preset) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'week' => [now()->startOfWeek()->toDateString(), now()->toDateString()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->toDateString()],
        };
    }

    /** Filtres validés, partagés avec l'export CSV. */
    public static function filters(array $input): array
    {
        $parse = function ($v, Carbon $default) {
            try {
                return $v ? Carbon::parse($v) : $default;
            } catch (\Throwable) {
                return $default;
            }
        };
        $from = $parse($input['from'] ?? null, now()->startOfMonth())->startOfDay();
        $to = $parse($input['to'] ?? null, now())->endOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [
            'from' => $from,
            'to' => $to,
            'group' => in_array($input['group'] ?? '', ['day', 'week', 'month'], true) ? $input['group'] : 'day',
            'register_id' => ($input['register'] ?? '') ?: null,
            'user_id' => ($input['user'] ?? '') ?: null,
            'location_id' => ($input['location'] ?? '') ?: null,
            'type' => in_array($input['type'] ?? '', ['medicine', 'parapharmacy'], true) ? $input['type'] : null,
        ];
    }

    public function render(ReportService $reports, DashboardService $dashboard)
    {
        $pharmacyId = auth()->user()->pharmacy_id;
        $query = ['report' => $this->report, 'from' => $this->from, 'to' => $this->to, 'group' => $this->group,
            'register' => $this->register, 'user' => $this->user, 'location' => $this->location, 'type' => $this->type];

        return view('livewire.reports.report-index', [
            'tables' => $this->report === 'dashboard' ? [] : $reports->build($this->report, $pharmacyId, self::filters($query)),
            'dashboard' => $this->report === 'dashboard' ? $dashboard->build($pharmacyId, self::filters($query)) : null,
            'query' => $query,
            'registers' => Register::where('pharmacy_id', $pharmacyId)->orderBy('code')->get(),
            'users' => User::where('pharmacy_id', $pharmacyId)->orderBy('name')->get(),
            'locations' => Location::where('pharmacy_id', $pharmacyId)->where('type', '!=', 'store')->orderBy('name')->get(),
            'canExport' => auth()->user()->hasPermission('report.export'),
        ]);
    }
}
