<?php

namespace App\Livewire\System;

use App\Models\Backup;
use App\Services\Backup\BackupService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Sauvegardes - PharmaPOS')]
class BackupIndex extends Component
{
    use WithPagination;

    public function mount(): void
    {
        abort_unless(self::allowed(), 403);
    }

    public static function allowed(): bool
    {
        // SaaS : la base est commune à toutes les pharmacies, seul l'exploitant la sauvegarde
        return (bool) auth()->user()?->is_super_admin;
    }

    public function backupNow(BackupService $backups): void
    {
        abort_unless(self::allowed(), 403);
        $backup = $backups->run('manual', auth()->user());
        $backup->status === 'success'
            ? session()->flash('status', "Sauvegarde {$backup->filename} créée.")
            : $this->addError('backup', 'Échec : '.$backup->error);
    }

    public function testRestore(string $id, BackupService $backups): void
    {
        abort_unless(self::allowed(), 403);
        $backup = Backup::where('status', 'success')->findOrFail($id);
        $result = $backups->testRestore($backup, auth()->user());
        $result['ok']
            ? session()->flash('status', 'Restauration testée : '.$backup->fresh()->restore_result)
            : $this->addError('backup', $backup->fresh()->restore_result);
    }

    public function render(BackupService $backups)
    {
        return view('livewire.system.backup-index', [
            'backups' => Backup::with('user')->latest('started_at')->paginate(20),
            'issue' => $backups->issue(),
            'secondary' => config('backup.secondary_path'),
            'keyMissing' => ! config('backup.key'),
        ]);
    }
}
