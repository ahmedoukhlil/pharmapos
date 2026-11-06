{{--
    Bouton caméra pour un écran ouvert directement sur un téléphone (caisse, inventaire…).
    method : méthode Livewire appelée avec le code lu. once : fermer la caméra après la première lecture réussie.
--}}
@props(['method', 'once' => false])

@vite('resources/js/barcode.js')
<div wire:ignore x-data="cameraScan($wire, @js($method), @js((bool) $once))" x-show="available" x-cloak {{ $attributes }}>
    <button type="button" x-on:click="open()" title="Scanner avec la caméra"
            class="h-full min-h-10 rounded-lg bg-emerald-700 px-4 text-white shadow-sm hover:bg-emerald-800">
        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M10 8v8M13 8v8M17 8v8"/></svg>
    </button>

    <template x-teleport="body">
        {{-- 100dvh : la hauteur visible réelle, barre de Safari exclue --}}
        <div x-show="active" x-cloak class="fixed inset-x-0 top-0 z-50 flex flex-col bg-slate-950 text-white" style="height: 100dvh; background: #020617; color: #fff">
            {{-- Barre du haut : toujours visible --}}
            <div class="flex items-center gap-2 px-3 pb-2" style="padding-top: max(0.75rem, env(safe-area-inset-top))">
                <span class="text-base font-semibold">Scanner</span>
                <span class="text-xs text-slate-400" x-text="@js((bool) $once) ? 'un produit' : 'en continu'"></span>
                <button type="button" x-show="torch" x-on:click="toggleTorch()" :class="torchOn ? 'bg-amber-400 text-slate-900' : 'bg-white/10'"
                        class="ms-auto rounded-full px-3 py-2 text-sm" title="Lampe">💡 Lampe</button>
                <button type="button" x-on:click="close()" :class="torch ? '' : 'ms-auto'" class="rounded-full bg-white/10 px-3 py-2 text-lg leading-none" title="Fermer">✕</button>
            </div>

            {{-- Aperçu compact : on vise, on ne regarde pas un film --}}
            <div class="relative mx-3 overflow-hidden rounded-2xl bg-black" style="height: 42dvh">
                <video x-ref="video" playsinline muted autoplay class="h-full w-full object-cover"></video>
                {{-- Cadre de visée en forme de code-barres, avec l'extérieur assombri --}}
                <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div class="relative h-24 w-4/5 rounded-xl border-[3px] transition-colors duration-150"
                         style="box-shadow: 0 0 0 9999px rgb(0 0 0 / 0.45)"
                         :class="flash === 'ok' ? 'border-emerald-400' : (flash === 'error' ? 'border-red-500' : 'border-white')">
                        <div class="absolute inset-x-3 top-1/2 h-0.5 -translate-y-1/2 bg-red-500/80"></div>
                    </div>
                </div>
                <p x-show="starting && !message" class="absolute inset-x-0 bottom-3 text-center text-sm text-slate-300">Ouverture de la caméra…</p>
                <p x-show="!starting && !message" class="absolute inset-x-0 bottom-3 text-center text-sm text-white/90">Placez le code-barres dans le cadre, à 15–20 cm</p>
                <p x-show="engine" x-text="engine" class="absolute end-2 top-2 rounded bg-black/50 px-1.5 text-[10px] text-white/70"></p>
                <p x-show="message" x-text="message" class="absolute inset-x-3 top-3 rounded-lg bg-red-600 px-3 py-2 text-sm"></p>
            </div>

            {{-- Derniers codes lus et leur résultat --}}
            <ul class="mx-3 mt-3 min-h-0 flex-1 space-y-1.5 overflow-y-auto">
                <template x-for="(h, i) in history" :key="i + h.code">
                    <li class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm" :class="h.ok ? 'bg-white/10' : 'bg-red-600/30'">
                        <span x-text="h.ok ? '✓' : '✕'" :class="h.ok ? 'text-emerald-400' : 'text-red-300'" class="font-bold"></span>
                        <span x-text="h.code" class="font-mono"></span>
                        <span x-text="h.text" class="ms-auto truncate text-xs text-slate-300"></span>
                    </li>
                </template>
                <li x-show="!history.length" class="px-1 text-sm text-slate-400">Aucun code lu pour l'instant.</li>
            </ul>

            {{-- Saisie manuelle et fin : au-dessus de la barre du navigateur --}}
            <div class="space-y-2 px-3 pt-2" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom))">
                <form x-on:submit.prevent="submitManual()" class="flex gap-2">
                    <input x-model="manual" inputmode="numeric" autocomplete="off" placeholder="Code illisible ? Tapez-le"
                           class="min-w-0 flex-1 rounded-xl border-0 bg-white/10 px-3 py-3 font-mono text-white placeholder:text-slate-400 focus:ring-2 focus:ring-emerald-400">
                    <button class="rounded-xl bg-white/15 px-4 text-sm font-medium">OK</button>
                </form>
                <button type="button" x-on:click="close()" class="w-full rounded-xl bg-emerald-600 py-4 text-lg font-semibold">
                    Terminer<span x-show="count" x-text="' (' + count + ')'"></span>
                </button>
            </div>
        </div>
    </template>
</div>
