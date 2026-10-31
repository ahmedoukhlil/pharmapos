@php
    $badges = ['ok' => ['À importer', 'bg-emerald-100 text-emerald-800'], 'duplicate' => ['Doublon', 'bg-amber-100 text-amber-800'], 'error' => ['Erreur', 'bg-red-100 text-red-800']];
    $report = $result ?? $preview;
@endphp

<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-5xl space-y-4 p-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('catalogue.index') }}" class="text-sm text-emerald-800 hover:underline">← Catalogue</a>
            <h1 class="text-xl font-semibold">Import du catalogue</h1>
            <a href="{{ route('catalogue.template') }}" class="ms-auto text-sm text-emerald-800 hover:underline">Télécharger le modèle CSV</a>
        </div>

        <div class="space-y-2 rounded-lg bg-white p-4 text-sm shadow-sm">
            <p>Fichier Excel (.xlsx) ou CSV, première ligne = en-têtes. Obligatoires : <code>nom_commercial</code>, <code>sku</code>, <code>prix_vente</code> (et <code>dci</code> pour un médicament).</p>
            <p class="text-xs text-slate-500">Colonnes reconnues : {{ implode(', ', $columns) }}. Plusieurs codes-barres séparés par des espaces ou « ; ». Les SKU déjà présents sont ignorés.</p>
            <input type="file" wire:model="file" accept=".csv,.txt,.xlsx" class="block text-sm">
            <div wire:loading wire:target="file" class="text-slate-500">Analyse du fichier…</div>
            @error('file') <p class="text-red-700" role="alert">{{ $message }}</p> @enderror
        </div>

        @if ($report)
            <div @class(['flex flex-wrap items-center gap-4 rounded-lg p-4 text-sm shadow-sm', 'bg-emerald-50' => $result, 'bg-white' => ! $result])>
                <span>{{ $result ? 'Importés' : 'À importer' }} : <strong>{{ $report['created'] }}</strong></span>
                <span>Doublons ignorés : <strong>{{ $report['skipped'] }}</strong></span>
                <span>Erreurs : <strong>{{ $report['errors'] }}</strong></span>
                @if ($preview && $preview['created'] > 0)
                    <button wire:click="import" wire:loading.attr="disabled"
                            wire:confirm="Importer {{ $preview['created'] }} présentation(s) ? Les lignes en erreur ou en doublon seront ignorées."
                            class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Importer les lignes valides</button>
                @endif
                @if ($result)
                    <span class="ms-auto font-medium text-emerald-800">Import terminé.</span>
                @endif
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2 text-end">Ligne</th>
                            <th class="px-3 py-2 text-start">Produit</th>
                            <th class="px-3 py-2 text-start">État</th>
                            <th class="px-3 py-2 text-start">Détail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach (collect($report['lines'])->sortBy(fn ($l) => [$l['status'] === 'ok', $l['line']]) as $l)
                            <tr wire:key="l-{{ $l['line'] }}">
                                <td class="px-3 py-2 text-end font-mono">{{ $l['line'] }}</td>
                                <td class="px-3 py-2">{{ $l['label'] }}</td>
                                <td class="px-3 py-2"><span class="rounded px-2 py-0.5 text-xs {{ $badges[$l['status']][1] }}">{{ $badges[$l['status']][0] }}</span></td>
                                <td class="px-3 py-2 text-slate-600">{{ $l['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </main>
</div>
