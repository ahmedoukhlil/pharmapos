@php $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm'; @endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-5xl space-y-4 p-4">
        <div class="flex items-center">
            <h1 class="text-xl font-semibold">Fournisseurs</h1>
            @if ($editing === null)
                <button wire:click="edit" class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Nouveau fournisseur</button>
            @endif
        </div>

        @if ($editing !== null)
            <form wire:submit="save" class="grid gap-3 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-3">
                @foreach (['code' => 'Code *', 'name' => 'Raison sociale *', 'contact_name' => 'Contact', 'phone' => 'Téléphone', 'email' => 'E-mail', 'nif' => 'NIF', 'address' => 'Adresse', 'payment_terms_days' => 'Délai de paiement (jours)'] as $f => $l)
                    <label class="block">
                        <span class="text-sm font-medium">{{ $l }}</span>
                        <input wire:model="form.{{ $f }}" @if ($f === 'payment_terms_days') type="number" min="0" @endif class="{{ $input }}">
                        @error($f) <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <label class="flex items-center gap-2 self-end pb-2 text-sm">
                    <input type="checkbox" wire:model="form.is_active"> Actif
                </label>
                <div class="flex gap-2 sm:col-span-3">
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
                    <button type="button" wire:click="$set('editing', null)" class="rounded-lg px-3 py-2 text-sm">Annuler</button>
                </div>
            </form>
        @endif

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">Code</th>
                        <th class="px-3 py-2 text-start">Nom</th>
                        <th class="px-3 py-2 text-start">Contact</th>
                        <th class="px-3 py-2 text-end">Délai</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($suppliers as $s)
                        <tr wire:key="{{ $s->id }}" @class(['hover:bg-slate-50', 'text-slate-400' => ! $s->is_active])>
                            <td class="px-3 py-2 font-mono">{{ $s->code }}</td>
                            <td class="px-3 py-2">{{ $s->name }} @unless ($s->is_active) (inactif) @endunless</td>
                            <td class="px-3 py-2">{{ $s->contact_name }} {{ $s->phone }}</td>
                            <td class="px-3 py-2 text-end">{{ $s->payment_terms_days }} j</td>
                            <td class="px-3 py-2 text-end"><button wire:click="edit('{{ $s->id }}')" class="text-emerald-800 hover:underline">Modifier</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">Aucun fournisseur.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
</div>
