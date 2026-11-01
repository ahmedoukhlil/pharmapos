@php
    $reports = ['dashboard' => 'Tableau de bord'] + \App\Services\Reports\ReportService::REPORTS;
    $periodic = in_array($report, ['dashboard', 'sales', 'expiry', 'cash'], true);
    $fmt = fn ($v) => match (true) {
        is_int($v) => number_format($v, 0, ',', ' '),
        is_float($v) => number_format($v, 2, ',', ' '),
        default => $v ?? '—',
    };
    $select = 'rounded-lg border border-slate-300 px-3 py-1.5 text-sm';
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-7xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">Rapports</h1>
            <nav class="-mx-1 flex max-w-full gap-1 overflow-x-auto px-1 text-sm whitespace-nowrap print:hidden">
                @foreach ($reports as $k => $label)
                    <button wire:click="$set('report', '{{ $k }}')" @class(['rounded-full px-3 py-1', 'bg-emerald-700 text-white' => $report === $k, 'bg-white shadow-sm' => $report !== $k])>{{ $label }}</button>
                @endforeach
            </nav>
            <button onclick="window.print()" class="ms-auto rounded-lg border border-slate-300 px-3 py-1.5 text-sm print:hidden">Imprimer / PDF</button>
        </div>

        <div class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-3 text-sm shadow-sm print:hidden">
            @if ($periodic)
                <label>Du <input type="date" wire:model.live="from" class="{{ $select }}"></label>
                <label>au <input type="date" wire:model.live="to" class="{{ $select }}"></label>
                <div class="flex gap-1">
                    @foreach (['today' => "Aujourd'hui", 'week' => 'Semaine', 'month' => 'Mois', 'last_month' => 'Mois dernier'] as $k => $v)
                        <button wire:click="period('{{ $k }}')" class="rounded px-2 py-1 text-xs hover:bg-slate-100">{{ $v }}</button>
                    @endforeach
                </div>
            @endif
            @if ($report === 'sales')
                <select wire:model.live="group" class="{{ $select }}">
                    <option value="day">Par jour</option>
                    <option value="week">Par semaine</option>
                    <option value="month">Par mois</option>
                </select>
                <select wire:model.live="user" class="{{ $select }}">
                    <option value="">Tous les caissiers</option>
                    @foreach ($users as $u) <option value="{{ $u->id }}">{{ $u->name }}</option> @endforeach
                </select>
            @endif
            @if (in_array($report, ['dashboard', 'sales', 'cash'], true))
                <select wire:model.live="register" class="{{ $select }}">
                    <option value="">Toutes les caisses</option>
                    @foreach ($registers as $r) <option value="{{ $r->id }}">{{ $r->name }}</option> @endforeach
                </select>
            @endif
            @if ($report === 'stock')
                <select wire:model.live="location" class="{{ $select }}">
                    <option value="">Tous les emplacements</option>
                    @foreach ($locations as $l) <option value="{{ $l->id }}">{{ $l->name }}</option> @endforeach
                </select>
                <select wire:model.live="type" class="{{ $select }}">
                    <option value="">Médicaments et parapharmacie</option>
                    <option value="medicine">Médicaments</option>
                    <option value="parapharmacy">Parapharmacie</option>
                </select>
            @endif
        </div>

        <p class="hidden text-sm print:block">{{ $reports[$report] }} — {{ $periodic ? \Illuminate\Support\Carbon::parse($from)->format('d/m/Y').' au '.\Illuminate\Support\Carbon::parse($to)->format('d/m/Y') : 'au '.now()->format('d/m/Y H:i') }}</p>

        @if ($dashboard)
            @include('livewire.reports.dashboard', ['d' => $dashboard])
        @endif

        @foreach ($tables as $i => $t)
            <section wire:key="t-{{ $report }}-{{ $i }}" class="overflow-x-auto rounded-lg bg-white shadow-sm print:break-inside-avoid print:shadow-none">
                <div class="flex items-center px-3 py-2">
                    <h2 class="font-semibold">{{ $t['title'] }}</h2>
                    @if ($canExport && $t['rows'])
                        <a href="{{ route('reports.export', $query + ['table' => $i]) }}" class="ms-auto text-sm text-emerald-800 hover:underline print:hidden">Exporter CSV</a>
                    @endif
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            @foreach ($t['columns'] as $key => $label)
                                <th class="px-3 py-2 {{ is_numeric($t['rows'][0][$key] ?? null) ? 'text-end' : 'text-start' }}">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($t['rows'] as $row)
                            <tr>
                                @foreach ($t['columns'] as $key => $label)
                                    <td @class(['px-3 py-1.5', 'text-end tabular-nums' => is_numeric($row[$key]), 'text-red-700' => in_array($row[$key], ['Rupture', 'Périmé'], true) || ($key === 'difference' && ($row[$key] ?? 0) < 0)])>{{ $fmt($row[$key]) }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($t['columns']) }}" class="px-3 py-6 text-center text-slate-500">Aucune donnée pour ces filtres.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($t['totals'])
                        <tfoot class="bg-slate-50 font-semibold">
                            <tr>
                                @foreach ($t['columns'] as $key => $label)
                                    <td class="px-3 py-2 {{ is_numeric($t['totals'][$key]) ? 'text-end tabular-nums' : '' }}">{{ $t['totals'][$key] === '' ? '' : $fmt($t['totals'][$key]) }}</td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </section>
        @endforeach
    </main>
</div>
