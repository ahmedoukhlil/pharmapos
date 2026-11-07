<div wire:poll.5s
     x-data="{ seen: null }"
     x-effect="
        const ids = {{ Js::from($incoming) }};
        if (seen !== null && ids.some(id => ! seen.includes(id))) {
            try { const a = new AudioContext(), o = a.createOscillator(); o.frequency.value = 880; o.connect(a.destination); o.start(); o.stop(a.currentTime + 0.15); } catch (e) {}
        }
        seen = ids;
     ">
    @if ($sales->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2" aria-label="Ventes en attente">
            <span class="text-xs font-medium uppercase text-slate-500">En attente</span>
            @foreach ($sales as $held)
                @php $fromOther = $held->user_id !== auth()->id(); @endphp
                <span wire:key="held-{{ $held->id }}" @class(['inline-flex items-center overflow-hidden rounded-full border text-sm',
                        'border-sky-400 bg-sky-50 ring-2 ring-sky-200' => $fromOther, 'border-amber-300 bg-amber-50' => ! $fromOther])>
                    <button type="button" wire:click="$parent.resume('{{ $held->id }}')" class="px-3 py-1 hover:bg-white/60" title="Reprendre et encaisser">
                        @if ($fromOther) <strong class="text-sky-800">{{ \Illuminate\Support\Str::of($held->user?->name)->before(' ') }}</strong> · @endif
                        {{ $held->held_at->format('H:i') }} · {{ $held->items_count }} art. · {{ number_format($held->total, 2, ',', ' ') }}
                    </button>
                    <button type="button" wire:click="$parent.cancelHeld('{{ $held->id }}')" wire:confirm="Annuler cette vente en attente et libérer le stock ?"
                            class="border-s border-amber-300 px-2 py-1 text-amber-800 hover:bg-red-50 hover:text-red-700" aria-label="Annuler la vente en attente">✕</button>
                </span>
            @endforeach
        </div>
    @endif
</div>
