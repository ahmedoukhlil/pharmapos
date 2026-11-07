@php $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm'; @endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">Utilisateurs</h1>
            <input wire:model.live.debounce.300ms="search" placeholder="Rechercher un nom ou un identifiant" class="ms-auto w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="showInactive"> Afficher les inactifs</label>
            @if ($editing === null)
                <button wire:click="edit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Nouvel utilisateur</button>
            @endif
        </div>

        @error('user') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p> @enderror

        @if ($credentials)
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm">
                <p class="font-semibold text-emerald-900">Mot de passe provisoire de {{ $credentials[0] }}</p>
                <p class="mt-2 flex flex-wrap items-center gap-x-6 gap-y-1">
                    <span>Identifiant : <strong class="font-mono">{{ $credentials[1] }}</strong></span>
                    <span>Mot de passe : <strong class="rounded bg-white px-2 py-0.5 font-mono text-base tracking-wider">{{ $credentials[2] }}</strong></span>
                </p>
                <p class="mt-2 text-emerald-800">Communiquez-le à la personne : il ne sera plus affiché. Elle devra choisir son propre mot de passe à la première connexion.</p>
                <button wire:click="$set('credentials', null)" class="mt-2 text-emerald-900 underline">J'ai noté le mot de passe</button>
            </div>
        @endif

        @if ($editing !== null)
            <form wire:submit="save" class="grid gap-3 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-2">
                <h2 class="font-semibold sm:col-span-2">{{ $editing ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur' }}</h2>
                @foreach (['name' => 'Nom complet *', 'username' => 'Identifiant de connexion *', 'phone' => 'Téléphone', 'email' => 'E-mail'] as $f => $l)
                    <label class="block">
                        <span class="text-sm font-medium">{{ $l }}</span>
                        <input wire:model="form.{{ $f }}" @if ($f === 'username') autocomplete="off" placeholder="ex. fatimetou" @endif class="{{ $input }}">
                        @error($f) <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium">Rôle *</span>
                    <select wire:model="form.role" class="{{ $input }}">
                        @foreach ($roles as $r)
                            <option value="{{ $r->slug }}">{{ $r->name }}{{ $r->max_discount_percent > 0 ? ' - remise jusqu\'à '.(float) $r->max_discount_percent.' %' : '' }}</option>
                        @endforeach
                    </select>
                    @error('role') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                </label>
                @unless ($editing)
                    <p class="text-sm text-slate-500 sm:col-span-2">Un mot de passe provisoire sera généré et affiché après l'enregistrement.</p>
                @endunless
                <div class="flex gap-2 sm:col-span-2">
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
                    <button type="button" wire:click="$set('editing', null)" class="rounded-lg px-3 py-2 text-sm">Annuler</button>
                </div>
            </form>
        @endif

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">Nom</th>
                        <th class="px-3 py-2 text-start">Identifiant</th>
                        <th class="px-3 py-2 text-start">Rôle</th>
                        <th class="px-3 py-2 text-start">Dernière connexion</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $u)
                        <tr wire:key="{{ $u->id }}" @class(['text-slate-400' => ! $u->is_active])>
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ $u->name }}</span>
                                @if ($u->is(auth()->user())) <span class="ms-1 rounded-full bg-slate-100 px-2 text-xs">vous</span> @endif
                                @unless ($u->is_active) <span class="ms-1 rounded-full bg-slate-100 px-2 text-xs">inactif</span> @endunless
                                @if ($u->must_change_password && $u->is_active) <span class="ms-1 rounded-full bg-amber-100 px-2 text-xs text-amber-800">mot de passe provisoire</span> @endif
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $u->username }}</td>
                            <td class="px-3 py-2">{{ $u->roleAssignments->first()?->role->name ?? '-' }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ $u->last_login_at?->format('d/m/Y H:i') ?? 'Jamais' }}</td>
                            <td class="space-x-3 whitespace-nowrap px-3 py-2 text-end">
                                <button wire:click="edit('{{ $u->id }}')" class="text-emerald-800 hover:underline">Modifier</button>
                                @unless ($u->is(auth()->user()))
                                    @if ($u->is_active)
                                        <button wire:click="resetPassword('{{ $u->id }}')" wire:confirm="Générer un nouveau mot de passe provisoire pour {{ $u->name }} ? L'ancien ne fonctionnera plus." class="text-slate-600 hover:underline">Nouveau mot de passe</button>
                                        <button wire:click="toggle('{{ $u->id }}')" wire:confirm="Désactiver le compte de {{ $u->name }} ? La personne sera déconnectée et ne pourra plus se connecter." class="text-red-700 hover:underline">Désactiver</button>
                                    @else
                                        <button wire:click="toggle('{{ $u->id }}')" class="text-emerald-800 hover:underline">Réactiver</button>
                                    @endif
                                    <button wire:click="delete('{{ $u->id }}')" wire:confirm="Supprimer définitivement le compte de {{ $u->name }} ? Possible seulement s'il n'a jamais été utilisé ; sinon, désactivez-le." class="text-red-700 hover:underline" title="Supprimer">🗑</button>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">Aucun utilisateur.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>

    <x-reauth-modal :pending="$reauth" />
</div>
