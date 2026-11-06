@php $money = fn ($v) => number_format((float) $v, 2, ',', ' '); @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Reçu {{ $sale->ticket_number }}</title>
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
    <div class="c b">{{ $sale->pharmacy->name }}</div>
    <div class="c small">
        {{ $sale->pharmacy->address }}@if ($sale->pharmacy->city), {{ $sale->pharmacy->city }}@endif<br>
        @if ($sale->pharmacy->phone)Tél. {{ $sale->pharmacy->phone }}<br>@endif
        @if ($sale->pharmacy->nif)NIF {{ $sale->pharmacy->nif }}@endif
    </div>
    <hr>
    <div>Reçu : <span class="b">{{ $sale->ticket_number }}</span></div>
    <div>{{ $sale->finalized_at->timezone($sale->pharmacy->timezone)->format('d/m/Y H:i') }} · {{ $sale->register->name }}</div>
    <div>Caissier : {{ $sale->user->name }}</div>
    <hr>
    <table>
        @foreach ($sale->items as $item)
            <tr><td colspan="2" class="b">{{ $item->presentation->medicine->brand_name }} {{ $item->presentation->medicine->strength }}</td></tr>
            <tr>
                <td>{{ $item->quantity }} {{ $item->sale_unit === 'unit' ? $item->presentation->base_unit : 'bte' }} × {{ $money($item->unit_price) }}</td>
                <td class="r">{{ $money($item->line_total) }}</td>
            </tr>
            @if ((float) $item->discount_amount > 0)
                <tr><td class="small">Remise</td><td class="r small">−{{ $money($item->discount_amount) }}</td></tr>
            @endif
            <tr><td colspan="2" class="small">
                Lot @foreach ($item->batches as $b){{ $b->batch->lot_number }} ({{ $b->batch->expiry_date->format('m/Y') }})@if (! $loop->last), @endif @endforeach
            </td></tr>
        @endforeach
    </table>
    <hr>
    <table>
        @if ((float) $sale->rounding_adjustment != 0)
            <tr><td>Arrondi</td><td class="r">{{ $money($sale->rounding_adjustment) }}</td></tr>
        @endif
        <tr class="b"><td>TOTAL MRU</td><td class="r">{{ $money($sale->total) }}</td></tr>
        @foreach ($sale->payments as $p)
            <tr><td>{{ $p->method->name }}@if ($p->reference) <span class="small">réf. {{ $p->reference }}</span>@endif</td><td class="r">{{ $money($p->amount) }}</td></tr>
        @endforeach
        @if ((float) $sale->change_given > 0)
            <tr><td>Rendu</td><td class="r">{{ $money($sale->change_given) }}</td></tr>
        @endif
    </table>
    <hr>
    <div class="c small">Merci de votre visite.<br>Conservez ce reçu pour tout retour.</div>
</div>

<div class="actions">
    <button onclick="window.print()">Imprimer</button>
</div>

@if ($autoPrint)
    <script>window.addEventListener('load', () => window.print());</script>
@endif
</body>
</html>
