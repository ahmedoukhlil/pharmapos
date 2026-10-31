<div class="min-h-screen">
    <x-nav />

    <main class="mx-auto max-w-6xl space-y-4 p-4">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold">Sauvegardes</h1>
            <button wire:click="backupNow" wire:loading.attr="disabled" class="ms-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                <span wire:loading.remove wire:target="backupNow">Sauvegarder maintenant</span>
                <span wire:loading wire:target="backupNow">Sauvegarde en cours…</span>
            </button>
        </div>

        @if (session('status')) <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p> @endif
        @error('backup') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p> @enderror
        @if ($keyMissing)
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">BACKUP_KEY n'est pas définie : générez-la avec <code>php artisan pharmapos:backup --generate-key</code> et ajoutez-la au fichier .env.</p>
        @elseif ($issue)
            <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ $issue }}</p>
        @endif

        <div class="rounded-lg bg-white p-3 text-sm text-slate-600 shadow-sm">
            Sauvegarde automatique toutes les 15 minutes, chiffrée (AES-256-GCM) ; test de restauration quotidien.
            Support distinct : <strong>{{ $secondary ?: 'non configuré (BACKUP_SECONDARY_PATH)' }}</strong>.
            Rétention : toutes les sauvegardes sur {{ config('backup.keep_days') }} jours, puis une par jour sur {{ config('backup.keep_daily') }} jours.
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">Date</th>
                        <th class="px-3 py-2 text-start">Statut</th>
                        <th class="px-3 py-2 text-start">Fichier</th>
                        <th class="px-3 py-2 text-end">Taille</th>
                        <th class="px-3 py-2 text-start">Copie</th>
                        <th class="px-3 py-2 text-start">Restauration</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($backups as $b)
                        <tr wire:key="b-{{ $b->id }}">
                            <td class="whitespace-nowrap px-3 py-2">{{ $b->started_at->format('d/m/Y H:i') }} <span class="text-xs text-slate-500">{{ $b->trigger === 'manual' ? '· '.$b->user?->name : '' }}</span></td>
                            <td class="px-3 py-2">
                                <span @class(['rounded-full px-2 text-xs font-semibold', 'bg-emerald-100 text-emerald-800' => $b->status === 'success', 'bg-red-100 text-red-800' => $b->status === 'failed', 'bg-slate-100' => $b->status === 'running'])>
                                    {{ ['success' => 'Réussie', 'failed' => 'Échec', 'running' => 'En cours'][$b->status] }}
                                </span>
                                @if ($b->error) <p class="text-xs text-red-700">{{ $b->error }}</p> @endif
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $b->filename }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $b->size ? number_format($b->size / 1024, 0, ',', ' ').' Ko' : '' }}</td>
                            <td class="px-3 py-2">{{ $b->status === 'success' ? ($b->secondary_copied ? 'Oui' : 'Non') : '' }}</td>
                            <td class="px-3 py-2 text-xs">
                                @if ($b->restore_tested_at)
                                    <span @class(['text-emerald-800' => str_starts_with($b->restore_result, 'OK'), 'text-red-700' => ! str_starts_with($b->restore_result, 'OK')])>{{ $b->restore_result }}</span>
                                    <span class="text-slate-500">({{ $b->restore_tested_at->format('d/m H:i') }})</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-end">
                                @if ($b->status === 'success')
                                    <button wire:click="testRestore('{{ $b->id }}')" wire:loading.attr="disabled" class="text-emerald-800 hover:underline">Tester la restauration</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-slate-500">Aucune sauvegarde.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $backups->links() }}
    </main>
</div>
