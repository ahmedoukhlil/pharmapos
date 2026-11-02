@php
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $p = $order->pharmacy;
    $s = $order->supplier;
    $packs = $order->lines->sum('quantity_packs');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bon de commande {{ $order->number }}</title>
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: #0f172a; font: 11px/1.45 system-ui, 'Segoe UI', Arial, sans-serif; }
        .page { max-width: 210mm; margin: 6mm auto; background: #fff; padding: 12mm; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8mm; border-bottom: 2px solid #065f46; padding-bottom: 4mm; }
        .brand { display: flex; gap: 3mm; align-items: center; }
        .brand img { height: 14mm; }
        .brand h1 { margin: 0; font-size: 16px; color: #065f46; }
        .muted { color: #64748b; }
        .doc { text-align: right; }
        .doc h2 { margin: 0; font-size: 18px; letter-spacing: .5px; }
        .doc .num { font-family: ui-monospace, Consolas, monospace; font-size: 13px; font-weight: 600; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; margin: 6mm 0; }
        .box { border: 1px solid #cbd5e1; border-radius: 2mm; padding: 3mm 4mm; }
        .box h3 { margin: 0 0 1.5mm; font-size: 9px; text-transform: uppercase; letter-spacing: .6px; color: #64748b; }
        .box .name { font-size: 13px; font-weight: 600; }
        .meta { display: flex; gap: 8mm; margin-bottom: 4mm; }
        .meta div span { display: block; font-size: 9px; text-transform: uppercase; color: #64748b; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #065f46; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .4px; padding: 2mm; text-align: left; }
        td { padding: 1.8mm 2mm; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        .r { text-align: right; } .c { text-align: center; }
        .mono { font-family: ui-monospace, Consolas, monospace; font-size: 10px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .totals { margin-left: auto; width: 75mm; margin-top: 4mm; }
        .totals td { border: 0; padding: 1mm 2mm; }
        .totals .grand td { border-top: 2px solid #0f172a; font-size: 13px; font-weight: 700; padding-top: 2mm; }
        .notes { margin-top: 5mm; }
        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 10mm; margin-top: 12mm; page-break-inside: avoid; }
        .sign div { border-top: 1px solid #94a3b8; padding-top: 2mm; min-height: 22mm; }
        .foot { margin-top: 8mm; font-size: 9px; color: #94a3b8; text-align: center; }
        .actions { text-align: center; margin: 4mm 0; }
        .actions button { font: inherit; font-size: 13px; padding: 2.5mm 6mm; border-radius: 2mm; border: 0; background: #065f46; color: #fff; cursor: pointer; }
        @media print {
            body { background: #fff; }
            .page { margin: 0; padding: 0; box-shadow: none; max-width: none; }
            .actions { display: none; }
            th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tbody tr:nth-child(even) td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media (max-width: 640px) { .page { padding: 5mm; } .parties { grid-template-columns: 1fr; } .head { flex-direction: column; } .doc { text-align: left; } }
    </style>
</head>
<body>
<div class="actions"><button onclick="window.print()">Imprimer / Enregistrer en PDF</button></div>
<div class="page">
    <div class="head">
        <div class="brand">
            <img src="{{ asset('images/icon-192.png') }}" alt="" onerror="this.remove()">
            <div>
                <h1>{{ $p->legal_name ?: $p->name }}</h1>
                <div class="muted">
                    {{ $p->address }}@if ($p->city) — {{ $p->city }}@endif<br>
                    @if ($p->phone)Tél. {{ $p->phone }}@endif @if ($p->nif) · NIF {{ $p->nif }}@endif
                </div>
            </div>
        </div>
        <div class="doc">
            <h2>BON DE COMMANDE</h2>
            <div class="num">{{ $order->number }}</div>
            <div class="muted">Date : {{ ($order->ordered_at ?? $order->created_at)->format('d/m/Y') }}</div>
        </div>
    </div>

    <div class="parties">
        <div class="box">
            <h3>Fournisseur</h3>
            <div class="name">{{ $s->name }}</div>
            @if ($s->contact_name)<div>À l'attention de {{ $s->contact_name }}</div>@endif
            @if ($s->address)<div>{{ $s->address }}</div>@endif
            <div class="muted">
                @if ($s->phone)Tél. {{ $s->phone }}@endif @if ($s->email) · {{ $s->email }}@endif
                @if ($s->nif)<br>NIF {{ $s->nif }}@endif
            </div>
        </div>
        <div class="box">
            <h3>Livrer à</h3>
            <div class="name">{{ $p->name }}</div>
            @if ($p->address)<div>{{ $p->address }}</div>@endif
            @if ($p->city)<div>{{ $p->city }}</div>@endif
            @if ($p->phone)<div class="muted">Tél. {{ $p->phone }}</div>@endif
        </div>
    </div>

    <div class="meta">
        <div><span>Livraison souhaitée</span>{{ $order->expected_at?->format('d/m/Y') ?? 'Dès que possible' }}</div>
        <div><span>Conditions de paiement</span>{{ $s->payment_terms_days ? $s->payment_terms_days.' jours' : 'Comptant' }}</div>
        <div><span>Établi par</span>{{ $order->creator->name }}</div>
    </div>

    <table>
        <thead>
        <tr>
            <th class="c" style="width:7mm">#</th>
            <th>Désignation</th>
            <th>Code-barres / SKU</th>
            <th class="r">Qté (boîtes)</th>
            <th class="r">Prix unit.</th>
            <th class="r">Montant</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($order->lines as $i => $l)
            @php $m = $l->presentation->medicine; $code = $l->presentation->barcodes->firstWhere('is_active', true)?->barcode; @endphp
            <tr>
                <td class="c muted">{{ $i + 1 }}</td>
                <td>
                    <strong>{{ $m->brand_name }} {{ $m->strength }}</strong>
                    <div class="muted">{{ collect([$m->inn, $m->dosage_form, $l->presentation->label])->filter()->implode(' · ') }}</div>
                </td>
                <td class="mono">{{ $code ?? $l->presentation->sku }}</td>
                <td class="r"><strong>{{ $l->quantity_packs }}</strong></td>
                <td class="r">{{ $money($l->unit_price) }}</td>
                <td class="r">{{ $money($l->quantity_packs * $l->unit_price) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">Lignes</td><td class="r">{{ $order->lines->count() }}</td></tr>
        <tr><td class="muted">Boîtes</td><td class="r">{{ $packs }}</td></tr>
        <tr class="grand"><td>Total {{ $p->currency }}</td><td class="r">{{ $money($order->lines->sum(fn ($l) => $l->quantity_packs * $l->unit_price)) }}</td></tr>
    </table>

    @if ($order->notes)
        <div class="notes box"><h3>Notes</h3>{!! nl2br(e($order->notes)) !!}</div>
    @endif

    <div class="notes muted">Merci d'indiquer les numéros de lot et dates de péremption sur le bon de livraison. Les produits doivent avoir une péremption d'au moins 6 mois à la livraison.</div>

    <div class="sign">
        <div><strong>Le pharmacien</strong><br><span class="muted">Signature et cachet</span></div>
        <div><strong>Le fournisseur</strong><br><span class="muted">Accusé de réception</span></div>
    </div>

    <div class="foot">{{ $p->name }} — Bon de commande {{ $order->number }} — édité le {{ now()->timezone($p->timezone)->format('d/m/Y H:i') }}</div>
</div>
</body>
</html>
