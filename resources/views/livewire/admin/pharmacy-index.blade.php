@php
    $input = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none';
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
@endphp

<div>
    <x-admin-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">Pharmacies</h1>
            <a href="{{ route('admin.pharmacies.create') }}" class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Nouvelle pharmacie</a>
        </div>

        @if ($backupIssue)
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
                Sauvegarde : {{ $backupIssue }} <a href="{{ route('backups.index') }}" class="ms-1 font-semibold underline">Voir les sauvegardes</a>
            </p>
        @endif

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([['Pharmacies', $counts['total'], 'text-slate-900', ''], ['Actives', $counts['active'], 'text-emerald-700', ''],
                       ['Abonnement expiré', $counts['expired'], 'text-amber-700', 'expired'], ['Suspendues', $counts['suspended'], 'text-red-700', 'suspended']] as [$label, $n, $color, $f])
                <button type="button" wire:click="$set('filter', '{{ $filter === $f ? '' : $f }}')"
                        @class(['rounded-xl bg-white p-4 text-start shadow-sm ring-1', 'ring-emerald-500' => $f && $filter === $f, 'ring-slate-900/5' => ! ($f && $filter === $f)])>
                    <span class="block text-xs text-slate-500">{{ $label }}</span>
                    <span class="block text-2xl font-semibold {{ $color }}">{{ $n }}</span>
                </button>
            @endforeach
        </div>

        @if ($credentials)
            @php [$pName, $pCode, $login, $password] = $credentials; @endphp
            <section class="rounded-xl bg-emerald-50 p-4 text-sm ring-1 ring-emerald-200">
                <div class="flex items-start gap-2">
                    <p class="font-semibold text-emerald-900">Nouveau mot de passe provisoire — {{ $pName }}</p>
                    <button wire:click="$set('credentials', null)" class="ms-auto text-slate-500 hover:text-slate-800" title="Fermer">✕</button>
                </div>
                <p class="mt-1">Code <span class="font-mono font-semibold">{{ $pCode }}</span> · identifiant <span class="font-mono font-semibold">{{ $login }}</span>
                    · mot de passe <span class="font-mono font-semibold">{{ $password }}</span></p>
                <p class="mt-1 text-xs text-slate-600">Il ne sera plus affiché. L’utilisateur devra le changer à sa connexion.</p>
            </section>
        @endif

        <div class="flex flex-wrap gap-2">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Rechercher (nom, code, ville)" class="{{ $input }} min-w-0 flex-1 sm:max-w-xs">
            <select wire:model.live="filter" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">Toutes</option>
                <option value="expiring">Échéance sous 15 jours</option>
                <option value="expired">Abonnement expiré</option>
                <option value="suspended">Suspendues</option>
            </select>
        </div>

        <div class="space-y-3">
            @forelse ($this->pharmacies as $p)
                @php
                    $state = ! $p->is_active ? ['Suspendue', 'bg-red-100 text-red-800']
                        : ($p->subscriptionExpired() ? ['Expirée', 'bg-amber-100 text-amber-900']
                        : ($p->subscription_ends_at && $p->subscription_ends_at->lte(today()->addDays(15)) ? ['Bientôt échue', 'bg-amber-50 text-amber-800'] : ['Active', 'bg-emerald-100 text-emerald-800']));
                @endphp
                <article wire:key="p-{{ $p->id }}" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-4">
                        <div class="min-w-0 sm:flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-semibold">{{ $p->name }}</h2>
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs">{{ $p->code }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $state[1] }}">{{ $state[0] }}</span>
                            </div>
                            <p class="text-sm text-slate-500">
                                {{ $p->city ?: '—' }}{{ $p->phone ? ' · '.$p->phone : '' }}
                                · {{ $p->plan ?: 'Sans formule' }}
                                · {{ $p->subscription_ends_at ? 'échéance '.$p->subscription_ends_at->format('d/m/Y') : 'sans échéance' }}
                            </p>
                            @if (! $p->is_active && $p->suspended_reason)
                                <p class="text-sm text-red-700">Motif : {{ $p->suspended_reason }}</p>
                            @endif
                        </div>
                        <dl class="grid grid-cols-3 gap-3 rounded-lg bg-slate-50 p-2 text-sm sm:shrink-0 sm:gap-4 sm:bg-transparent sm:p-0">
                            <div><dt class="text-xs text-slate-500">Utilisateurs</dt><dd class="font-semibold tabular-nums">{{ $p->users_count }}</dd></div>
                            <div><dt class="text-xs text-slate-500">Ventes 30 j</dt><dd class="font-semibold tabular-nums">{{ $p->sales_30d }} <span class="block font-normal text-slate-500 sm:inline">{{ $money($p->revenue_30d) }} MRU</span></dd></div>
                            <div><dt class="text-xs text-slate-500">Dernière vente</dt><dd class="font-semibold">{{ $p->last_sale_at ? \Illuminate\Support\Carbon::parse($p->last_sale_at)->diffForHumans() : '—' }}</dd></div>
                        </dl>
                    </div>

                    @if ($editing === $p->id && $mode === 'users')
                        <div class="mt-3 rounded-lg bg-slate-50 p-3 text-sm">
                            <table class="no-cards w-full">
                                <thead class="hidden text-xs uppercase text-slate-500 sm:table-header-group"><tr><th class="py-1 text-start">Nom</th><th class="text-start">Identifiant</th><th class="text-start">Rôle</th><th class="text-start">Statut</th><th></th></tr></thead>
                                <tbody class="divide-y divide-slate-200">
                                    @foreach ($this->editingUsers as $u)
                                        <tr wire:key="u-{{ $u->id }}" class="flex flex-wrap items-center gap-x-2 py-2 sm:table-row">
                                            <td class="font-medium sm:py-1.5 sm:font-normal">{{ $u->name }}</td>
                                            <td class="font-mono">{{ $u->username }}</td>
                                            <td class="w-full text-slate-600 sm:w-auto">{{ $u->roles->pluck('name')->implode(', ') ?: '—' }}</td>
                                            <td class="text-slate-600">{{ $u->is_active ? 'Actif' : 'Désactivé' }}{{ $u->must_change_password ? ' · mot de passe provisoire' : '' }}</td>
                                            <td class="ms-auto text-end">
                                                <button wire:click="resetUser('{{ $u->id }}')" wire:confirm="Générer un mot de passe provisoire pour {{ $u->name }} ({{ $u->username }}) ?"
                                                        class="rounded-lg border border-slate-300 bg-white px-2 py-1 hover:bg-slate-100">Réinitialiser le mot de passe</button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <button type="button" wire:click="cancel" class="mt-2 rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-200">Fermer</button>
                        </div>
                    @elseif ($editing === $p->id)
                        <form wire:submit="save" class="mt-3 flex flex-wrap items-end gap-2 rounded-lg bg-slate-50 p-3">
                            @if ($mode === 'suspend')
                                <label class="min-w-0 flex-1">
                                    <span class="text-xs font-medium">Motif de la suspension (interne, non montré aux utilisateurs)</span>
                                    <input wire:model="form.reason" class="{{ $input }} mt-1" placeholder="ex. Facture impayée" autofocus>
                                    @error('reason') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                                    @error('form.reason') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                                </label>
                                <button class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Suspendre</button>
                            @else
                                <label class="w-full sm:w-40">
                                    <span class="text-xs font-medium">Formule</span>
                                    <input wire:model="form.plan" class="{{ $input }} mt-1">
                                </label>
                                <label class="w-full sm:w-44">
                                    <span class="text-xs font-medium">Échéance (vide = aucune)</span>
                                    <input wire:model="form.subscription_ends_at" type="date" class="{{ $input }} mt-1">
                                    @error('form.subscription_ends_at') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                                </label>
                                <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Enregistrer</button>
                            @endif
                            <button type="button" wire:click="cancel" class="rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-200">Annuler</button>
                        </form>
                    @else
                        <div class="mt-3 flex flex-wrap gap-2 text-sm">
                            <button wire:click="extend('{{ $p->id }}', 1)" class="rounded-lg border border-emerald-700 px-3 py-1.5 text-emerald-800 hover:bg-emerald-50">+1 mois</button>
                            <button wire:click="extend('{{ $p->id }}', 12)" class="rounded-lg border border-emerald-700 px-3 py-1.5 text-emerald-800 hover:bg-emerald-50">+1 an</button>
                            <button wire:click="edit('{{ $p->id }}', 'subscription')" class="rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-50">Abonnement…</button>
                            <button wire:click="resetOwner('{{ $p->id }}')" wire:confirm="Générer un nouveau mot de passe provisoire pour le propriétaire de {{ $p->name }} ?"
                                    class="rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-50">Réinitialiser le propriétaire</button>
                            <button wire:click="edit('{{ $p->id }}', 'users')" class="rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-50">Utilisateurs…</button>
                            @if ($p->is_active)
                                <button wire:click="edit('{{ $p->id }}', 'suspend')" class="ms-auto rounded-lg px-3 py-1.5 text-red-700 hover:bg-red-50">Suspendre</button>
                            @else
                                <button wire:click="reactivate('{{ $p->id }}')" class="ms-auto rounded-lg bg-emerald-700 px-3 py-1.5 font-semibold text-white hover:bg-emerald-800">Réactiver</button>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <p class="rounded-xl bg-white p-8 text-center text-slate-500 shadow-sm">
                    Aucune pharmacie{{ $search || $filter ? ' ne correspond' : '' }}.
                    @unless ($search || $filter) <a href="{{ route('admin.pharmacies.create') }}" class="font-semibold text-emerald-800 underline">Créer la première</a> @endunless
                </p>
            @endforelse
        </div>
    </main>
</div>
