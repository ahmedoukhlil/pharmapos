@php
    $fmt = fn ($v) => match (true) {
        is_int($v) => number_format($v, 0, ',', ' '),
        is_float($v) => number_format($v, 2, ',', ' '),
        default => $v ?? '—',
    };
    $wide = collect($tables)->max(fn ($t) => count($t['columns'])) > 6;
@endphp
<x-document :pharmacy="$pharmacy" title="Rapport" :number="$title" :landscape="$wide"
            :date="$periodic ? 'Du '.$filters['from']->format('d/m/Y').' au '.$filters['to']->format('d/m/Y') : 'Au '.now()->format('d/m/Y H:i')"
            :footer="'Rapport '.$title">
    <div class="meta"><div><span>Établi par</span>{{ $author }}</div></div>

    @foreach ($tables as $t)
        <h3 class="section">{{ $t['title'] }}</h3>
        <table>
            <thead>
            <tr>
                @foreach ($t['columns'] as $key => $label)
                    <th @class(['r' => is_numeric($t['rows'][0][$key] ?? null)])>{{ $label }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @forelse ($t['rows'] as $row)
                <tr>
                    @foreach ($t['columns'] as $key => $label)
                        <td @class(['r' => is_numeric($row[$key]), 'neg' => in_array($row[$key], ['Rupture', 'Périmé'], true) || ($key === 'difference' && ($row[$key] ?? 0) < 0)])>{{ $fmt($row[$key]) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($t['columns']) }}" class="c muted">Aucune donnée pour ces filtres.</td></tr>
            @endforelse
            </tbody>
            @if ($t['totals'])
                <tfoot>
                <tr>
                    @foreach ($t['columns'] as $key => $label)
                        <td @class(['r' => is_numeric($t['totals'][$key])])>{{ $t['totals'][$key] === '' ? '' : $fmt($t['totals'][$key]) }}</td>
                    @endforeach
                </tr>
                </tfoot>
            @endif
        </table>
    @endforeach
</x-document>
