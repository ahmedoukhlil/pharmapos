@php
    $money = fn ($v) => $v ? number_format((float) $v, 2, ',', ' ') : '';
@endphp
<x-document :pharmacy="$pharmacy" title="Relevé de compte fournisseur" :number="$supplier->name"
            :date="($from ? 'du '.$from->format('d/m/Y').' ' : '').'au '.($to ?? now())->format('d/m/Y')">
    <div class="meta">
        <div><span>Fournisseur</span>{{ $supplier->name }}{{ $supplier->code ? ' ('.$supplier->code.')' : '' }}</div>
        <div><span>Solde d'ouverture</span>{{ number_format($statement['opening'], 2, ',', ' ') }} MRU</div>
        <div><span>Acomptes non imputés</span>{{ number_format($advances, 2, ',', ' ') }} MRU</div>
        <div><span>Solde dû</span><strong>{{ number_format($statement['closing'], 2, ',', ' ') }} MRU</strong></div>
    </div>

    <table>
        <thead>
        <tr>
            <th style="width:22mm">Date</th><th>Libellé</th><th style="width:22mm">Échéance</th>
            <th class="r" style="width:28mm">Facturé</th><th class="r" style="width:28mm">Réglé</th><th class="r" style="width:30mm">Solde</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($statement['entries'] as $e)
            <tr>
                <td>{{ $e['date']->format('d/m/Y') }}</td>
                <td>{{ $e['label'] }}</td>
                <td>{{ $e['due']?->format('d/m/Y') }}</td>
                <td class="r">{{ $money($e['debit']) }}</td>
                <td class="r">{{ $money($e['credit']) }}</td>
                <td @class(['r', 'neg' => $e['balance'] < 0])>{{ number_format($e['balance'], 2, ',', ' ') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="c muted">Aucun mouvement sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>
    <table class="totals">
        <tr class="grand"><td>Solde dû (MRU)</td><td class="r">{{ number_format($statement['closing'], 2, ',', ' ') }}</td></tr>
    </table>
    <p class="muted">Un solde négatif signifie un crédit en faveur de la pharmacie (acompte supérieur aux factures).</p>
</x-document>
