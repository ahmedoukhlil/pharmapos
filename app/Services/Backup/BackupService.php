<?php

namespace App\Services\Backup;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Sauvegardes locales (PRD §13.4) : dump MySQL cohérent (--single-transaction), compressé puis
 * chiffré en AES-256-GCM, copié sur un support distinct, purgé selon la rétention, et dont la
 * restauration est testée dans une base jetable (QA-17).
 *
 * Format du fichier : « PPBK1 » + IV (12 octets) + tag GCM (16 octets) + gzip(SQL) chiffré.
 */
class BackupService
{
    public const MAGIC = 'PPBK1';

    public function run(string $trigger = 'schedule', ?User $user = null): Backup
    {
        $backup = Backup::create(['trigger' => $trigger, 'started_at' => now(), 'user_id' => $user?->id]);
        $sqlFile = tempnam(sys_get_temp_dir(), 'ppdump');

        try {
            $key = $this->key();
            $this->dump($sqlFile);
            $sql = file_get_contents($sqlFile);
            if (! str_contains($sql, '-- Dump completed')) {
                throw new RuntimeException('Dump incomplet (marqueur de fin absent).');
            }

            $iv = random_bytes(12);
            $cipher = openssl_encrypt(gzencode($sql, 6), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) {
                throw new RuntimeException('Échec du chiffrement.');
            }
            $content = self::MAGIC.$iv.$tag.$cipher;

            $dir = config('backup.path');
            File::ensureDirectoryExists($dir);
            $filename = 'pharmapos-'.now()->format('Ymd-His').'-'.substr($backup->id, -6).'.sql.gz.enc';
            File::put($dir.DIRECTORY_SEPARATOR.$filename, $content);

            $backup->update([
                'status' => 'success',
                'filename' => $filename,
                'size' => strlen($content),
                'checksum' => hash('sha256', $content),
                'secondary_copied' => $this->copySecondary($dir.DIRECTORY_SEPARATOR.$filename, $filename),
                'finished_at' => now(),
            ]);
            $this->prune();
        } catch (\Throwable $e) {
            $backup->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            Log::error('Sauvegarde échouée : '.$e->getMessage());
        } finally {
            @unlink($sqlFile);
        }

        if ($user) {
            $this->audit($user, 'backup.created', $backup, ['status' => $backup->status]);
        }

        return $backup;
    }

    /** Déchiffre une sauvegarde et renvoie le SQL ; contrôle l'intégrité (somme et tag GCM). */
    public function decrypt(Backup $backup): string
    {
        $path = $this->path($backup);
        if (! is_file($path)) {
            throw new RuntimeException('Fichier de sauvegarde introuvable.');
        }
        $content = file_get_contents($path);
        if (hash('sha256', $content) !== $backup->checksum) {
            throw new RuntimeException('Somme de contrôle invalide : fichier altéré.');
        }
        if (! str_starts_with($content, self::MAGIC)) {
            throw new RuntimeException('Format de sauvegarde inconnu.');
        }
        $offset = strlen(self::MAGIC);
        $gz = openssl_decrypt(substr($content, $offset + 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA,
            substr($content, $offset, 12), substr($content, $offset + 12, 16));
        if ($gz === false) {
            throw new RuntimeException('Déchiffrement impossible : clé incorrecte ou fichier altéré.');
        }

        return gzdecode($gz);
    }

    /**
     * Restaure la sauvegarde dans une base jetable, compare tables et séquences à la base courante,
     * puis supprime la base. Le résultat est consigné sur la sauvegarde.
     */
    public function testRestore(Backup $backup, ?User $user = null): array
    {
        $live = DB::connection()->getDatabaseName();
        $scratch = $live.'_restore_test';
        $admin = $this->connection(null);
        $result = ['ok' => false, 'tables' => 0, 'rows' => 0, 'issues' => []];

        try {
            $sql = $this->decrypt($backup);
            $admin->statement("DROP DATABASE IF EXISTS `$scratch`");
            $admin->statement("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->mysql($scratch, $sql);

            $restored = $this->connection($scratch);
            $tables = collect($restored->select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?', [$scratch]))->pluck('t');
            $missing = collect(DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?', [$live]))
                ->pluck('t')->diff($tables)->reject(fn ($t) => $t === 'backups');
            foreach ($missing as $t) {
                $result['issues'][] = "Table absente : $t";
            }

            foreach ($tables as $t) {
                $result['rows'] += (int) $restored->table($t)->count();
            }
            $result['tables'] = $tables->count();

            // Cohérence des séquences : aucun numéro émis au-delà du compteur sauvegardé
            if ($tables->contains('document_sequences') && $tables->contains('sales')) {
                $issued = (int) $restored->table('document_sequences')->where('document_type', 'ticket')->sum('last_number');
                $used = $restored->table('sales')->whereNotNull('ticket_number')->count();
                if ($used > $issued) {
                    $result['issues'][] = "Séquence des reçus en retard ($used reçus, compteur $issued)";
                }
            }
            $result['ok'] = $result['tables'] > 0 && ! $result['issues'];
        } catch (\Throwable $e) {
            $result['issues'][] = $e->getMessage();
        } finally {
            try {
                $admin->statement("DROP DATABASE IF EXISTS `$scratch`");
            } catch (\Throwable) {
            }
            DB::purge('backup_restore');
        }

        $backup->update([
            'restore_tested_at' => now(),
            'restore_result' => mb_substr($result['ok']
                ? "OK : {$result['tables']} tables, {$result['rows']} lignes"
                : 'Échec : '.implode(' ; ', $result['issues']), 0, 255),
        ]);
        if ($user) {
            $this->audit($user, 'backup.restore_tested', $backup, ['ok' => $result['ok']]);
        }

        return $result;
    }

    /** Rétention : tout garder sur keep_days jours, puis une sauvegarde par jour sur keep_daily jours. */
    public function prune(): int
    {
        $keepAll = now()->subDays(config('backup.keep_days'));
        $keepDaily = now()->subDays(config('backup.keep_daily'));
        $removed = 0;

        $old = Backup::where('status', 'success')->where('started_at', '<', $keepAll)->orderByDesc('started_at')->get();
        $seenDays = [];
        foreach ($old as $backup) {
            $day = $backup->started_at->toDateString();
            if ($backup->started_at->gte($keepDaily) && ! isset($seenDays[$day])) {
                $seenDays[$day] = true;

                continue;
            }
            @unlink($this->path($backup));
            if ($secondary = config('backup.secondary_path')) {
                @unlink($secondary.DIRECTORY_SEPARATOR.$backup->filename);
            }
            $backup->delete();
            $removed++;
        }
        Backup::where('status', 'failed')->where('started_at', '<', $keepDaily)->delete();

        return $removed;
    }

    /** Problème de sauvegarde à signaler, ou null : dernier essai en échec ou dernier succès trop ancien. */
    public function issue(): ?string
    {
        $last = Backup::where('status', '!=', 'running')->latest('started_at')->first();
        $lastSuccess = Backup::where('status', 'success')->latest('started_at')->first();

        return match (true) {
            ! $last => 'Aucune sauvegarde effectuée.',
            $last->status === 'failed' => 'Dernière sauvegarde en échec le '.$last->started_at->format('d/m/Y H:i').' : '.$last->error,
            $lastSuccess->started_at->lt(now()->subMinutes(config('backup.max_age_minutes'))) => 'Aucune sauvegarde réussie depuis le '.$lastSuccess->started_at->format('d/m/Y H:i').'.',
            ! config('backup.secondary_path') || ! $last->secondary_copied => 'Copie sur support distinct non effectuée.',
            default => null,
        };
    }

    public function path(Backup $backup): string
    {
        return config('backup.path').DIRECTORY_SEPARATOR.$backup->filename;
    }

    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    private function key(): string
    {
        $raw = (string) config('backup.key');
        $key = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('BACKUP_KEY absente ou invalide (32 octets en base64 attendus).');
        }

        return $key;
    }

    private function dump(string $file): void
    {
        $db = config('database.connections.'.config('database.default'));
        $result = Process::timeout(config('backup.timeout'))
            ->env(['MYSQL_PWD' => (string) $db['password']])
            ->run([config('backup.mysqldump'), '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'],
                '--single-transaction', '--quick', '--routines', '--triggers', '--hex-blob',
                '--default-character-set=utf8mb4', '--ignore-table='.$db['database'].'.backups',
                '--result-file='.$file, $db['database']]);
        if (! $result->successful()) {
            throw new RuntimeException('mysqldump : '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    private function mysql(string $database, string $sql): void
    {
        $db = config('database.connections.'.config('database.default'));
        $result = Process::timeout(config('backup.timeout'))
            ->env(['MYSQL_PWD' => (string) $db['password']])
            ->input($sql)
            ->run([config('backup.mysql'), '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'],
                '--default-character-set=utf8mb4', $database]);
        if (! $result->successful()) {
            throw new RuntimeException('mysql : '.trim($result->errorOutput()));
        }
    }

    private function copySecondary(string $source, string $filename): bool
    {
        $dir = config('backup.secondary_path');
        if (! $dir) {
            return false;
        }
        try {
            File::ensureDirectoryExists($dir);

            return File::copy($source, $dir.DIRECTORY_SEPARATOR.$filename)
                && hash_file('sha256', $dir.DIRECTORY_SEPARATOR.$filename) === hash_file('sha256', $source);
        } catch (\Throwable $e) {
            Log::warning('Copie secondaire de sauvegarde impossible : '.$e->getMessage());

            return false;
        }
    }

    /** Connexion distincte : les DDL n'interfèrent pas avec les transactions de la connexion principale. */
    private function connection(?string $database)
    {
        config(['database.connections.backup_restore' => array_merge(
            config('database.connections.'.config('database.default')), ['database' => $database])]);
        DB::purge('backup_restore');

        return DB::connection('backup_restore');
    }

    private function audit(User $user, string $action, Backup $backup, array $values): void
    {
        if (! $user->pharmacy_id) {   // super-administrateur : hors journal des pharmacies
            \Illuminate\Support\Facades\Log::info("$action par {$user->username}", ['backup' => $backup->id] + $values);

            return;
        }
        AuditLog::create(['pharmacy_id' => $user->pharmacy_id, 'user_id' => $user->id, 'action' => $action,
            'auditable_type' => 'backup', 'auditable_id' => $backup->id, 'new_values' => $values]);
    }
}
