{{--
    Bouton caméra pour un écran ouvert directement sur un téléphone (caisse, inventaire…).
    method : méthode Livewire appelée avec le code lu. once : fermer la caméra après la première lecture.
--}}
@props(['method', 'once' => false])

@vite('resources/js/barcode.js')
<div wire:ignore x-data="cameraScan($wire, @js($method), @js((bool) $once))" x-show="available" x-cloak {{ $attributes }}>
    <button type="button" x-on:click="open()" title="Scanner avec la caméra"
            class="h-full min-h-10 rounded-lg bg-emerald-700 px-4 text-white shadow-sm hover:bg-emerald-800">
        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M10 8v8M13 8v8M17 8v8"/></svg>
    </button>
    <template x-teleport="body">
        <div x-show="active" x-cloak class="fixed inset-0 z-50 flex flex-col bg-black">
            <div class="relative flex-1">
                <video x-ref="video" playsinline muted class="h-full w-full object-cover"></video>
                <div class="pointer-events-none absolute inset-x-8 top-1/2 h-40 -translate-y-1/2 rounded-xl border-4 transition-colors"
                     :class="flash ? 'border-emerald-400' : 'border-white/70'"></div>
                <p x-show="message" x-text="message" class="absolute inset-x-4 top-4 rounded-lg bg-red-600 px-3 py-2 text-sm text-white"></p>
                <p x-show="last" class="absolute inset-x-4 bottom-4 rounded-lg bg-black/70 px-3 py-2 text-center text-sm text-white">
                    Lu : <span class="font-mono" x-text="last"></span>
                </p>
            </div>
            <button type="button" x-on:click="close()" class="bg-slate-900 py-4 text-lg font-medium text-white">Terminer</button>
        </div>
    </template>
</div>
