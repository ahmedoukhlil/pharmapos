@php
    $state = $this->state;
    $polling = in_array($state, ['waiting_phone', 'awaiting_confirmation', 'active'], true);
@endphp

<div class="relative" @if ($polling) wire:poll.1s="poll(document.visibilityState === 'visible')" @endif>
    @if ($state === 'active' && ! $attached)
        <button wire:click="$toggle('open')" class="flex items-center gap-1 rounded bg-slate-200 px-2 py-1 text-xs text-slate-700" title="Le téléphone n'a pas le droit sur cet écran">
            <span class="h-2 w-2 rounded-full bg-slate-400"></span> Scanner : pas ici
        </button>
    @elseif ($state === 'active')
        <button wire:click="$toggle('open')" class="flex items-center gap-1 rounded bg-emerald-900 px-2 py-1 text-xs text-white">
            <span class="h-2 w-2 rounded-full bg-lime-400"></span> Scanner : {{ $this->scanner->user?->name }}
        </button>
    @elseif ($state === 'awaiting_confirmation')
        <button wire:click="$set('open', true)" class="animate-pulse rounded bg-amber-500 px-2 py-1 text-xs font-semibold text-slate-900">Téléphone à confirmer</button>
    @else
        <button wire:click="start" @class(["rounded px-2 py-1 text-xs", "hover:bg-emerald-700" => $mode === "pos", "border border-emerald-700 text-emerald-800 hover:bg-emerald-50" => $mode !== "pos"])>Scanner smartphone</button>
    @endif

    @if ($open && $state)
        <div class="absolute end-0 top-full z-40 mt-2 w-80 rounded-lg bg-white p-4 text-sm whitespace-normal text-slate-900 shadow-xl">
            @if ($state === 'waiting_phone' && $qr)
                <p class="font-semibold">Scannez ce QR avec le téléphone</p>
                <div class="mx-auto my-2 w-fit">{!! $qr !!}</div>
                <p class="text-xs text-slate-500">Valable 2 minutes, une seule fois. Le téléphone doit être sur le même Wi-Fi.</p>
                @if ($localhostWarning)
                    <p class="mt-2 rounded bg-amber-50 p-2 text-xs text-amber-800">Cette caisse est ouverte via « localhost » : le téléphone ne pourra pas joindre cette adresse. Ouvrez PharmaPOS sur le PC avec l'adresse IP du serveur.</p>
                @endif
            @elseif ($state === 'awaiting_confirmation')
                <p><strong>{{ $this->scanner->user?->name }}</strong> veut associer son téléphone à votre compte. Il suivra l'écran ouvert : caisse, réception, inventaire, fiche produit.</p>
                <div class="mt-3 flex gap-2">
                    <button wire:click="confirm" class="rounded-lg bg-emerald-700 px-3 py-2 font-semibold text-white">Confirmer</button>
                    <button wire:click="revoke" class="rounded-lg px-3 py-2 text-red-700 hover:bg-red-50">Refuser</button>
                </div>
            @elseif ($state === 'active' && ! $attached)
                <p>Le téléphone de <strong>{{ $this->scanner->user?->name }}</strong> est associé à votre compte, mais ne peut pas servir sur cet écran (droit manquant ou document clos).</p>
                <button wire:click="revoke" class="mt-3 rounded-lg px-3 py-2 text-red-700 hover:bg-red-50">Déconnecter le téléphone</button>
            @elseif ($state === 'active')
                <p>Téléphone de <strong>{{ $this->scanner->user?->name }}</strong> associé jusqu'à {{ $this->scanner->expires_at->format('H:i') }}.</p>
                <button wire:click="revoke" class="mt-3 rounded-lg px-3 py-2 text-red-700 hover:bg-red-50">Déconnecter le téléphone</button>
            @else
                <p>QR expiré.</p>
                <button wire:click="start" class="mt-2 rounded-lg bg-emerald-700 px-3 py-2 text-white">Générer un nouveau QR</button>
            @endif
        </div>
    @endif
</div>
