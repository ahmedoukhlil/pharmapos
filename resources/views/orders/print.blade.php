@php
    $p = $order->pharmacy;
    $s = $order->supplier;
    $packs = $order->lines->sum('quantity_packs');
@endphp
<x-document :pharmacy="$p" title="Bon de commande" :number="$order->number"
            :date="'Date : '.($order->ordered_at ?? $order->created_at)->format('d/m/Y')">
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
            <th class="r" style="width:30mm">Quantité (boîtes)</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($order->lines as $i => $l)
            @php $m = $l->presentation->medicine @endphp
            <tr>
                <td class="c muted">{{ $i + 1 }}</td>
                <td>
                    <strong>{{ $m->brand_name }} {{ $m->strength }}</strong>
                    <div class="muted">{{ collect([$m->inn, $m->dosage_form, $l->presentation->label])->filter()->implode(' · ') }}</div>
                </td>
                <td class="r"><strong>{{ $l->quantity_packs }}</strong></td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">Lignes</td><td class="r">{{ $order->lines->count() }}</td></tr>
        <tr><td class="muted">Boîtes</td><td class="r">{{ $packs }}</td></tr>
    </table>

    @if ($order->notes)
        <div class="notes box"><h3>Notes</h3>{!! nl2br(e($order->notes)) !!}</div>
    @endif

    <div class="notes muted">Merci d'indiquer les numéros de lot et dates de péremption sur le bon de livraison. Les produits doivent avoir une péremption d'au moins 6 mois à la livraison.</div>

    <div class="sign">
        <div><strong>Le pharmacien</strong><br><span class="muted">Signature et cachet</span></div>
        <div><strong>Le fournisseur</strong><br><span class="muted">Accusé de réception</span></div>
    </div>
</x-document>
