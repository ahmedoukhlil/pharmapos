@php
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200 focus:outline-none';
@endphp

<div>
    <x-admin-nav />

    <main class="mx-auto max-w-3xl space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-xl font-semibold">E-mails et notifications</h1>
            <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-emerald-100 text-emerald-800' => $configured, 'bg-amber-100 text-amber-800' => ! $configured])>
                {{ $configured ? 'Envoi configuré' : 'Envoi non configuré' }}
            </span>
        </div>

        @if ($message) <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $message }}</p> @endif
        @if ($error) <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $error }}</p> @endif

        <form wire:submit="save" class="space-y-4">
            <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
                <div>
                    <h2 class="font-semibold">Serveur d'envoi (SMTP)</h2>
                    <p class="text-sm text-slate-500">Les informations de votre messagerie (Gmail, Outlook, hébergeur…). Le mot de passe est chiffré et n'est jamais réaffiché.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block sm:col-span-2"><span class="text-sm font-medium">Serveur SMTP *</span>
                        <input wire:model="mail.host" placeholder="ex. smtp.gmail.com" class="{{ $input }}">
                        @error('mail.host') <span class="text-xs text-red-700">{{ $message }}</span> @enderror</label>
                    <label class="block"><span class="text-sm font-medium">Port *</span>
                        <input wire:model="mail.port" inputmode="numeric" class="{{ $input }}">
                        @error('mail.port') <span class="text-xs text-red-700">{{ $message }}</span> @enderror</label>
                    <label class="block"><span class="text-sm font-medium">Sécurité</span>
                        <select wire:model="mail.encryption" class="{{ $input }}">
                            <option value="tls">TLS (port 587)</option>
                            <option value="ssl">SSL (port 465)</option>
                            <option value="none">Aucune</option>
                        </select></label>
                    <label class="block"><span class="text-sm font-medium">Identifiant</span>
                        <input wire:model="mail.username" autocomplete="off" class="{{ $input }}"></label>
                    <label class="block"><span class="text-sm font-medium">Mot de passe</span>
                        <input type="password" wire:model="mail.password" autocomplete="new-password" placeholder="{{ $hasPassword ? '•••••••• (inchangé)' : '' }}" class="{{ $input }}"></label>
                    <label class="block sm:col-span-2"><span class="text-sm font-medium">Adresse d'envoi *</span>
                        <input type="email" wire:model="mail.from_address" placeholder="ex. notifications@votre-domaine.mr" class="{{ $input }}">
                        @error('mail.from_address') <span class="text-xs text-red-700">{{ $message }}</span> @enderror</label>
                    <label class="block"><span class="text-sm font-medium">Nom d'expéditeur *</span>
                        <input wire:model="mail.from_name" class="{{ $input }}">
                        @error('mail.from_name') <span class="text-xs text-red-700">{{ $message }}</span> @enderror</label>
                </div>
                <p class="text-xs text-slate-500">Gmail : activez la validation en deux étapes, puis créez un « mot de passe d'application » à saisir ici.</p>
            </section>

            <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
                <div>
                    <h2 class="font-semibold">Notifications automatiques</h2>
                    <p class="text-sm text-slate-500">Envoyées chaque matin à 7 h aux propriétaires et pharmaciens responsables qui ont une adresse e-mail dans leur fiche.</p>
                </div>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="notify.stock" class="mt-0.5">
                    <span><strong>Alertes de stock</strong> : produits sous le seuil de réapprovisionnement, lots périmés ou qui périment dans les 30 jours.</span></label>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="notify.subscription" class="mt-0.5">
                    <span><strong>Échéance de l'abonnement</strong> : rappels avant l'échéance, puis un e-mail le lendemain de l'expiration.</span></label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="text-sm font-medium">Rappels d'abonnement (jours avant)</span>
                        <input wire:model="notify.subscription_days" placeholder="15,7,3,1" class="{{ $input }}">
                        @error('notify.subscription_days') <span class="text-xs text-red-700">{{ $message }}</span> @enderror</label>
                    <label class="block"><span class="text-sm font-medium">Copie des e-mails d'abonnement à</span>
                        <input type="email" wire:model="notify.admin_copy" placeholder="ex. commercial@votre-societe.mr" class="{{ $input }}">
                        @error('notify.admin_copy') <span class="text-xs text-red-700">{{ $message }}</span> @enderror</label>
                </div>
            </section>

            <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Enregistrer</button>
        </form>

        <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
            <h2 class="font-semibold">Tester l'envoi</h2>
            <form wire:submit="sendTest" class="flex flex-wrap items-start gap-2">
                <label class="min-w-56 flex-1"><span class="sr-only">Adresse de test</span>
                    <input type="email" wire:model="testTo" placeholder="votre adresse e-mail" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('testTo') <span class="text-xs text-red-700">{{ $message }}</span> @enderror</label>
                <button class="rounded-lg border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">
                    <span wire:loading.remove wire:target="sendTest">Envoyer un e-mail de test</span>
                    <span wire:loading wire:target="sendTest">Envoi…</span>
                </button>
            </form>
        </section>
    </main>
</div>
