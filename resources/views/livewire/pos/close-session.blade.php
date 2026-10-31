@php $fmt = fn (?int $c) => $c === null ? '—' : number_format($c / 100, 2, ',', ' '); @endphp

<div class="min-h-screen">
    <x-nav :register="$registerCode" />

    <main class="mx-auto max-w-4xl space-y-4 p-4">
        <h1 class="text-xl font-semibold">Clôture — {{ $this->register?->name ?? 'caisse inconnue' }}</h1>

        @if ($error)
            <p class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">{{ $error }}</p>
        @endif
        @if ($message)
            <p class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800" role="status">{{ $message }}</p>
        @endif

        @if (! $this->session)
            <p class="rounded-lg bg-white p-6 text-slate-600 shadow-sm">Aucune session ouverte sur cette caisse.</p>
        @else
            @php $s = $this->summary; @endphp
            <div class="grid gap-4 md:grid-cols-2">
                {{-- Récapitulatif --}}
                <section class="rounded-lg bg-white p-4 shadow-sm">
                    <h2 class="mb-2 font-semibold">Récapitulatif</h2>
                    <p class="mb-3 text-xs text-slate-500">
                        Ouverte le {{ $this->session->opened_at->format('d/m/Y H:i') }} par {{ $this->session->opener->name }}
                        · {{ $s['sales_count'] }} vente(s) · {{ $fmt($s['sales_total']) }} MRU
                    </p>
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt>Fonds initial</dt><dd class="tabular-nums">{{ $fmt($s['opening']) }}</dd></div>
                        <div class="flex justify-between"><dt>Encaissements espèces</dt><dd class="tabular-nums">{{ $fmt($s['cash_sales']) }}</dd></div>
                        <div class="flex justify-between"><dt>Rendu de monnaie</dt><dd class="tabular-nums">{{ $s['change'] ? '−' : '' }}{{ $fmt($s['change']) }}</dd></div>
                        <div class="flex justify-between"><dt>Entrées</dt><dd class="tabular-nums">{{ $fmt($s['cash_in']) }}</dd></div>
                        <div class="flex justify-between"><dt>Sorties</dt><dd class="tabular-nums">{{ $s['cash_out'] ? '−' : '' }}{{ $fmt($s['cash_out']) }}</dd></div>
                        <div class="flex justify-between border-t border-slate-100 pt-1 font-semibold"><dt>Espèces théoriques</dt><dd class="tabular-nums">{{ $fmt($s['expected_cash']) }}</dd></div>
                    </dl>
                    <h3 class="mt-4 mb-1 text-sm font-semibold">Par mode de paiement (net)</h3>
                    <dl class="space-y-1 text-sm">
                        @forelse ($s['by_method'] as $m)
                            <div class="flex justify-between"><dt>{{ $m['name'] }}</dt><dd class="tabular-nums">{{ $fmt($m['amount']) }}</dd></div>
                        @empty
                            <p class="text-slate-500">Aucun encaissement.</p>
                        @endforelse
                    </dl>
                    <p class="mt-2 text-xs text-slate-500">Bankily et Masrvi sont déclaratifs : à rapprocher manuellement des relevés.</p>
                </section>

                <div class="space-y-4">
                    {{-- Comptage et clôture --}}
                    <form wire:submit="close" class="space-y-3 rounded-lg bg-white p-4 shadow-sm">
                        <h2 class="font-semibold">Comptage des espèces</h2>
                        <label class="block">
                            <span class="text-sm">Espèces comptées (MRU)</span>
                            <input wire:model.live.debounce.300ms="countedCash" type="number" min="0" step="0.01" required autofocus
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-end text-lg tabular-nums">
                        </label>
                        @if ($this->difference !== null)
                            <p @class([
                                'rounded-lg px-3 py-2 text-sm font-medium',
                                'bg-emerald-50 text-emerald-800' => $this->difference === 0,
                                'bg-amber-50 text-amber-900' => $this->difference > 0,
                                'bg-red-50 text-red-800' => $this->difference < 0,
                            ])>
                                Écart : {{ $this->difference > 0 ? '+' : '' }}{{ $fmt($this->difference) }} MRU
                                {{ $this->difference === 0 ? '(caisse juste)' : ($this->difference > 0 ? '(excédent)' : '(manquant)') }}
                            </p>
                        @endif
                        <label class="block">
                            <span class="text-sm">Commentaire {{ $this->difference ? '(recommandé en cas d\'écart)' : '' }}</span>
                            <textarea wire:model="notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                        </label>
                        <button wire:confirm="Clôturer la session ? Aucune vente ne sera plus possible sur cette session."
                                class="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white hover:bg-emerald-800">
                            Clôturer la session
                        </button>
                    </form>

                    {{-- Mouvements d'espèces --}}
                    @if ($canMove)
                        <form wire:submit="recordMovement" class="space-y-3 rounded-lg bg-white p-4 shadow-sm">
                            <h2 class="font-semibold">Entrée / sortie d'espèces</h2>
                            <div class="flex gap-2">
                                <select wire:model="movementType" class="rounded-lg border border-slate-300 px-2 py-2 text-sm">
                                    <option value="cash_out">Sortie</option>
                                    <option value="cash_in">Entrée</option>
                                </select>
                                <input wire:model="movementAmount" type="number" min="0.01" step="0.01" placeholder="Montant" required
                                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-end tabular-nums">
                            </div>
                            <input wire:model="movementReason" placeholder="Motif (dépôt banque, achat de fournitures…)" required
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <button class="w-full rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50">Enregistrer le mouvement</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </main>

    <x-reauth-modal :pending="$reauth" />
</div>
