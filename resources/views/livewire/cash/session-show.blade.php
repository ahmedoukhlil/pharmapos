@php
    $fmt = fn (int $c) => number_format($c / 100, 2, ',', ' ');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
    $labels = ['open' => 'Ouverte', 'closed' => 'Clôturée — à valider', 'validated' => 'Validée'];
    $s = $session;
@endphp

<div class="min-h-screen">
    <x-nav :register="$s->register->code" />

    <main class="mx-auto max-w-3xl space-y-4 p-4 print:p-0">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold">Session {{ $s->register->name }} — {{ $s->opened_at->format('d/m/Y') }}</h1>
            <span @class([
                'rounded-full px-2 py-0.5 text-xs font-medium',
                'bg-sky-100 text-sky-800' => $s->status === 'open',
                'bg-amber-100 text-amber-900' => $s->status === 'closed',
                'bg-emerald-100 text-emerald-800' => $s->status === 'validated',
            ])>{{ $labels[$s->status] }}</span>
            <button onclick="window.print()" class="ms-auto rounded-lg border border-slate-300 px-3 py-1 text-sm hover:bg-white print:hidden">Imprimer</button>
        </div>

        @if ($error)
            <p class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">{{ $error }}</p>
        @endif

        <section class="grid gap-4 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-2 print:shadow-none">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>Ouverture</dt><dd>{{ $s->opened_at->format('d/m H:i') }} · {{ $s->opener->name }}</dd></div>
                <div class="flex justify-between"><dt>Clôture</dt><dd>{{ $s->closed_at?->format('d/m H:i') ?? '—' }} {{ $s->closer ? '· '.$s->closer->name : '' }}</dd></div>
                <div class="flex justify-between"><dt>Validation</dt><dd>{{ $s->validated_at?->format('d/m H:i') ?? '—' }} {{ $s->validator ? '· '.$s->validator->name : '' }}</dd></div>
                <div class="flex justify-between"><dt>Ventes</dt><dd>{{ $summary['sales_count'] }} · {{ $fmt($summary['sales_total']) }} MRU</dd></div>
            </dl>
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>Fonds initial</dt><dd class="tabular-nums">{{ $fmt($summary['opening']) }}</dd></div>
                <div class="flex justify-between"><dt>Espèces encaissées</dt><dd class="tabular-nums">{{ $fmt($summary['cash_sales']) }}</dd></div>
                <div class="flex justify-between"><dt>Rendu</dt><dd class="tabular-nums">{{ $summary['change'] ? '−' : '' }}{{ $fmt($summary['change']) }}</dd></div>
                <div class="flex justify-between"><dt>Entrées / sorties</dt><dd class="tabular-nums">{{ $fmt($summary['cash_in']) }} / {{ $summary['cash_out'] ? '−' : '' }}{{ $fmt($summary['cash_out']) }}</dd></div>
                <div class="flex justify-between font-semibold"><dt>Théorique</dt><dd class="tabular-nums">{{ $money($s->expected_cash) }}</dd></div>
                <div class="flex justify-between font-semibold"><dt>Compté</dt><dd class="tabular-nums">{{ $money($s->counted_cash) }}</dd></div>
                <div @class(['flex justify-between font-semibold', 'text-red-700' => (float) $s->difference < 0, 'text-amber-700' => (float) $s->difference > 0])>
                    <dt>Écart</dt><dd class="tabular-nums">{{ $money($s->difference) }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg bg-white p-4 shadow-sm print:shadow-none">
            <h2 class="mb-2 font-semibold">Encaissements par mode {{ $s->totals_by_method ? '(figés à la clôture)' : '' }}</h2>
            <dl class="space-y-1 text-sm">
                @forelse ($s->totals_by_method ?? array_map(fn ($m) => ['name' => $m['name'], 'amount' => $m['amount'] / 100], $summary['by_method']) as $m)
                    <div class="flex justify-between"><dt>{{ $m['name'] }}</dt><dd class="tabular-nums">{{ $money($m['amount']) }}</dd></div>
                @empty
                    <p class="text-slate-500">Aucun encaissement.</p>
                @endforelse
            </dl>
        </section>

        @if ($s->movements->isNotEmpty())
            <section class="rounded-lg bg-white p-4 shadow-sm print:shadow-none">
                <h2 class="mb-2 font-semibold">Mouvements d'espèces</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($s->movements as $m)
                        <li class="flex gap-3 py-1">
                            <span class="w-24">{{ $m->created_at->format('d/m H:i') }}</span>
                            <span class="flex-1">{{ $m->reason }} <span class="text-slate-500">· {{ $m->user->name }}</span></span>
                            <span class="tabular-nums">{{ $m->type === 'cash_out' ? '−' : '+' }}{{ $money($m->amount) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($s->notes)
            <section class="rounded-lg bg-white p-4 text-sm shadow-sm print:shadow-none">
                <h2 class="mb-1 font-semibold">Commentaire de clôture</h2>
                <p>{{ $s->notes }}</p>
            </section>
        @endif

        @if ($s->status === 'closed' && $canValidate)
            <form wire:submit="validateSession" class="space-y-3 rounded-lg bg-white p-4 shadow-sm print:hidden">
                <h2 class="font-semibold">Validation</h2>
                <textarea wire:model="comment" rows="2" placeholder="Commentaire (facultatif)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                <button wire:confirm="Valider et verrouiller définitivement cette session ?"
                        class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Valider la clôture</button>
            </form>
        @endif
    </main>
</div>
