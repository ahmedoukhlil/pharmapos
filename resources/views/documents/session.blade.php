@php
    $fmt = fn (int $c) => number_format($c / 100, 2, ',', ' ');
    $money = fn ($v) => $v === null ? '-' : number_format((float) $v, 2, ',', ' ');
    $labels = ['open' => 'Ouverte', 'closed' => 'Clôturée - à valider', 'validated' => 'Validée'];
    $methods = $s->totals_by_method ?? array_map(fn ($m) => ['name' => $m['name'], 'amount' => $m['amount'] / 100], $summary['by_method']);
@endphp
<x-document :pharmacy="$s->pharmacy" title="Session de caisse" :number="$s->register->name"
            :date="$s->opened_at->format('d/m/Y').' · '.$labels[$s->status]">
    <div class="meta">
        <div><span>Ouverture</span>{{ $s->opened_at->format('d/m/Y H:i') }} · {{ $s->opener->name }}</div>
        <div><span>Clôture</span>{{ $s->closed_at?->format('d/m/Y H:i') ?? '-' }}{{ $s->closer ? ' · '.$s->closer->name : '' }}</div>
        <div><span>Validation</span>{{ $s->validated_at?->format('d/m/Y H:i') ?? '-' }}{{ $s->validator ? ' · '.$s->validator->name : '' }}</div>
        <div><span>Ventes</span>{{ $summary['sales_count'] }} · {{ $fmt($summary['sales_total']) }} MRU</div>
    </div>

    <h3 class="section">Contrôle des espèces</h3>
    <table>
        <tbody>
        <tr><td>Fonds initial</td><td class="r">{{ $fmt($summary['opening']) }}</td></tr>
        <tr><td>Espèces encaissées</td><td class="r">{{ $fmt($summary['cash_sales']) }}</td></tr>
        <tr><td>Rendu</td><td class="r">{{ $summary['change'] ? '−' : '' }}{{ $fmt($summary['change']) }}</td></tr>
        <tr><td>Entrées</td><td class="r">{{ $fmt($summary['cash_in']) }}</td></tr>
        <tr><td>Sorties</td><td class="r">{{ $summary['cash_out'] ? '−' : '' }}{{ $fmt($summary['cash_out']) }}</td></tr>
        </tbody>
    </table>
    <table class="totals">
        <tr><td>Théorique</td><td class="r">{{ $money($s->expected_cash) }}</td></tr>
        <tr><td>Compté</td><td class="r">{{ $money($s->counted_cash) }}</td></tr>
        <tr class="grand"><td>Écart</td><td @class(['r', 'neg' => (float) $s->difference < 0])>{{ $money($s->difference) }}</td></tr>
    </table>

    <h3 class="section">Encaissements par mode {{ $s->totals_by_method ? '(figés à la clôture)' : '' }}</h3>
    <table>
        <thead><tr><th>Mode de paiement</th><th class="r" style="width:40mm">Montant (MRU)</th></tr></thead>
        <tbody>
        @forelse ($methods as $m)
            <tr><td>{{ $m['name'] }}</td><td class="r">{{ $money($m['amount']) }}</td></tr>
        @empty
            <tr><td colspan="2" class="c muted">Aucun encaissement.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if ($s->movements->isNotEmpty())
        <h3 class="section">Mouvements d'espèces</h3>
        <table>
            <thead><tr><th style="width:28mm">Date</th><th>Motif</th><th>Opérateur</th><th class="r" style="width:30mm">Montant</th></tr></thead>
            <tbody>
            @foreach ($s->movements as $m)
                <tr>
                    <td>{{ $m->created_at->format('d/m H:i') }}</td>
                    <td>{{ $m->reason }}</td>
                    <td>{{ $m->user->name }}</td>
                    <td @class(['r', 'neg' => $m->type === 'cash_out'])>{{ $m->type === 'cash_out' ? '−' : '+' }}{{ $money($m->amount) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($s->notes)
        <div class="notes box"><h3>Commentaire de clôture</h3>{!! nl2br(e($s->notes)) !!}</div>
    @endif

    <div class="sign">
        <div><strong>Le caissier</strong><br><span class="muted">{{ $s->closer?->name ?? $s->opener->name }}</span></div>
        <div><strong>Le responsable</strong><br><span class="muted">Contrôle et signature</span></div>
    </div>
</x-document>
