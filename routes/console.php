<?php

use App\Services\Sales\SaleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('pos:release-expired-reservations', function (SaleService $sales) {
    $this->info($sales->releaseExpiredReservations().' réservation(s) libérée(s).');
})->purpose('Libère le stock réservé par les ventes en attente expirées (POS-15)');

Schedule::command('pos:release-expired-reservations')->everyMinute()->withoutOverlapping();

Artisan::command('pharmapos:super-admin {username} {--name=Administrateur} {--reset : Change le mot de passe d\'un super-administrateur existant}', function (\App\Services\Admin\TenantService $tenants) {
    $password = $this->secret('Mot de passe (10 caractères minimum, lettres et chiffres)');
    if (strlen((string) $password) < 10 || ! preg_match('/[a-z]/i', $password) || ! preg_match('/\d/', $password)) {
        $this->error('Mot de passe trop faible.');

        return 1;
    }
    if ($this->option('reset')) {
        $user = \App\Models\User::where('is_super_admin', true)->where('username', strtolower($this->argument('username')))->first();
        if (! $user) {
            $this->error('Aucun super-administrateur avec cet identifiant. Existants : '.\App\Models\User::where('is_super_admin', true)->pluck('username')->implode(', '));

            return 1;
        }
        $user->forceFill(['password' => $password, 'must_change_password' => false, 'is_active' => true])->save();
        $this->info("Mot de passe de {$user->username} changé : connectez-vous en laissant le code pharmacie vide.");

        return 0;
    }
    try {
        $user = $tenants->createSuperAdmin(['name' => $this->option('name'), 'username' => $this->argument('username'), 'password' => $password]);
    } catch (\Illuminate\Validation\ValidationException $e) {
        $this->error(collect($e->errors())->flatten()->implode(' '));

        return 1;
    }
    $this->info("Super-administrateur {$user->username} créé : connectez-vous en laissant le code pharmacie vide.");

    return 0;
})->purpose('Crée un compte super-administrateur SaaS (gestion des pharmacies)');

Artisan::command('pharmapos:backup {--generate-key : Affiche une nouvelle clé BACKUP_KEY} {--test-restore : Teste la restauration de la dernière sauvegarde}', function (\App\Services\Backup\BackupService $backups) {
    if ($this->option('generate-key')) {
        $this->line('BACKUP_KEY='.\App\Services\Backup\BackupService::generateKey());
        $this->warn('Conservez cette clé hors de la machine : sans elle, les sauvegardes sont illisibles.');

        return 0;
    }
    if ($this->option('test-restore')) {
        $last = \App\Models\Backup::where('status', 'success')->latest('started_at')->first();
        if (! $last) {
            $this->error('Aucune sauvegarde réussie à tester.');

            return 1;
        }
        $result = $backups->testRestore($last);
        $this->{$result['ok'] ? 'info' : 'error'}($last->filename.' : '.$last->fresh()->restore_result);

        return $result['ok'] ? 0 : 1;
    }

    $backup = $backups->run();
    $this->{$backup->status === 'success' ? 'info' : 'error'}($backup->status === 'success'
        ? "Sauvegarde {$backup->filename} (".number_format($backup->size / 1024, 0, ',', ' ').' Ko)'
        : 'Échec : '.$backup->error);

    return $backup->status === 'success' ? 0 : 1;
})->purpose('Sauvegarde chiffrée de la base (PRD §13.4)');

Schedule::command('pharmapos:backup')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('pharmapos:backup --test-restore')->dailyAt('03:30')->withoutOverlapping();
