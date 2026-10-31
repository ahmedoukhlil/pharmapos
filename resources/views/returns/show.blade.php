@php $money = fn ($v) => number_format((float) $v, 2, ',', ' '); $p = $return->pharmacy; @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Avoir {{ $return->credit_note_number }}</title>
    <style>
        @page { size: {{ $width }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #000; font: 12px/1.35 ui-monospace, 'Courier New', monospace; }
        .ticket { width: {{ $width }}mm; padding: 3mm; margin: 0 auto; }
        .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
        hr { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 0; }
        .small { font-size: 10px; }
        .actions { text-align: center; margin: 4mm 0; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
<div class="ticket">
    <div class="c b">{{ $p->name }}</div>
    <div class="c small">{{ $p->address }}@if ($p->city), {{ $p->city }}@endif<br>@if ($p->nif)NIF {{ $p->nif }}@endif</div>
    <hr>
    <div class="c b">AVOIR {{ $return->credit_note_number }}</div>
    <div>{{ $return->created_at->timezone($p->timezone)->format('d/m/Y H:i') }}</div>
    <div>Ticket d'origine : {{ $return->sale->ticket_number }}</div>
    <div>Opérateur : {{ $return->user->name }}</div>
    <div class="small">Motif : {{ $return->reason }}</div>
    <hr>
    <table>
        @foreach ($return->items as $item)
            @php $si = $item->saleItemBatch->saleItem; $factor = $si->sale_unit === 'pack' ? $si->presentation->units_per_pack : 1; @endphp
            <tr><td colspan="2" class="b">{{ $si->presentation->medicine->brand_name }} {{ $si->presentation->medicine->strength }}</td></tr>
            <tr>
                <td>{{ intdiv($item->quantity_base_units, $factor) }} {{ $si->sale_unit === 'pack' ? 'bte' : $si->presentation->base_unit }} · lot {{ $item->saleItemBatch->batch->lot_number }}</td>
                <td class="r">−{{ $money($item->amount) }}</td>
            </tr>
        @endforeach
    </table>
    <hr>
    <table>
        <tr class="b"><td>REMBOURSÉ MRU</td><td class="r">{{ $money($return->total) }}</td></tr>
        @foreach ($refunds as $r)
            <tr><td>{{ $r->method->name }}@if ($r->reference) <span class="small">réf. {{ $r->reference }}</span>@endif</td><td class="r">{{ $money(-$r->amount) }}</td></tr>
        @endforeach
    </table>
    <hr>
    <div class="c small">Signature du client :<br><br><br></div>
</div>
<div class="actions"><button onclick="window.print()">Imprimer</button></div>
@if ($autoPrint)
    <script>window.addEventListener('load', () => window.print());</script>
@endif
</body>
</html>
