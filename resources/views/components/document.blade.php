{{--
    Mise en page commune des documents A4 imprimables (bon de commande, session de caisse, rapports).
    Le ticket de caisse et l'avoir restent au format imprimante thermique.
--}}
@props(['pharmacy', 'title', 'number' => null, 'date' => null, 'footer' => null, 'landscape' => false])
@php $p = $pharmacy; @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}{{ $number ? ' '.$number : '' }}</title>
    <style>
        @page { size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: #0f172a; font: 11px/1.45 system-ui, 'Segoe UI', Arial, sans-serif; }
        .page { max-width: {{ $landscape ? '297mm' : '210mm' }}; margin: 6mm auto; background: #fff; padding: 12mm; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8mm; border-bottom: 2px solid #065f46; padding-bottom: 4mm; margin-bottom: 6mm; }
        .brand { display: flex; gap: 3mm; align-items: center; }
        .brand img { height: 14mm; }
        .brand h1 { margin: 0; font-size: 16px; color: #065f46; }
        .muted { color: #64748b; }
        .doc { text-align: right; }
        .doc h2 { margin: 0; font-size: 18px; letter-spacing: .5px; text-transform: uppercase; }
        .doc .num { font-family: ui-monospace, Consolas, monospace; font-size: 13px; font-weight: 600; }
        h3.section { margin: 6mm 0 2mm; font-size: 12px; color: #065f46; text-transform: uppercase; letter-spacing: .5px; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; margin-bottom: 6mm; }
        .box { border: 1px solid #cbd5e1; border-radius: 2mm; padding: 3mm 4mm; }
        .box h3 { margin: 0 0 1.5mm; font-size: 9px; text-transform: uppercase; letter-spacing: .6px; color: #64748b; }
        .box .name { font-size: 13px; font-weight: 600; }
        .meta { display: flex; flex-wrap: wrap; gap: 4mm 8mm; margin-bottom: 4mm; }
        .meta div span { display: block; font-size: 9px; text-transform: uppercase; color: #64748b; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #065f46; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .4px; padding: 2mm; text-align: left; }
        td { padding: 1.8mm 2mm; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        tfoot td { font-weight: 700; border-top: 2px solid #0f172a; border-bottom: 0; }
        .r { text-align: right; font-variant-numeric: tabular-nums; } .c { text-align: center; }
        .neg { color: #b91c1c; }
        .mono { font-family: ui-monospace, Consolas, monospace; font-size: 10px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .totals { margin-left: auto; width: 75mm; margin-top: 4mm; }
        .totals td { border: 0; padding: 1mm 2mm; background: none !important; }
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
            th, tbody tr:nth-child(even) td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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
                    {{ $p->address }}@if ($p->city) - {{ $p->city }}@endif<br>
                    @if ($p->phone)Tél. {{ $p->phone }}@endif @if ($p->nif) · NIF {{ $p->nif }}@endif
                </div>
            </div>
        </div>
        <div class="doc">
            <h2>{{ $title }}</h2>
            @if ($number)<div class="num">{{ $number }}</div>@endif
            @if ($date)<div class="muted">{{ $date }}</div>@endif
        </div>
    </div>

    {{ $slot }}

    <div class="foot">{{ $p->name }} - {{ $footer ?? $title.($number ? ' '.$number : '') }} - édité le {{ now()->timezone($p->timezone)->format('d/m/Y H:i') }}</div>
</div>
</body>
</html>
