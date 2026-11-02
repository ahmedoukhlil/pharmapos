<?php

namespace Tests\Feature;

use App\Livewire\Alerts\AlertIndex;
use App\Livewire\System\BackupIndex;
use App\Models\Backup;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Alerts\AlertService;
use App\Services\Backup\BackupService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sauvegardes locales chiffrées (PRD §13.4) : dump, chiffrement, copie distincte, rétention,
 * test de restauration (QA-17) et alerte d'échec.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pp-backup-test-'.uniqid();
        config([
            'backup.key' => BackupService::generateKey(),
            'backup.path' => $this->dir.DIRECTORY_SEPARATOR.'main',
            'backup.secondary_path' => $this->dir.DIRECTORY_SEPARATOR.'usb',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    public function test_backup_is_encrypted_copied_and_restorable(): void
    {
        $service = app(BackupService::class);
        $backup = $service->run();

        $this->assertSame('success', $backup->status, (string) $backup->error);
        $this->assertTrue($backup->secondary_copied);
        $this->assertFileExists($this->dir.'/usb/'.$backup->filename);
        $raw = file_get_contents($service->path($backup));
        $this->assertStringStartsWith(BackupService::MAGIC, $raw);
        $this->assertStringNotContainsString('PARA500-16', $raw);   // illisible sans la clé

        $sql = $service->decrypt($backup);
        $this->assertStringContainsString('PARA500-16', $sql);

        $result = $service->testRestore($backup);
        $this->assertTrue($result['ok'], implode(' ; ', $result['issues']));
        $this->assertStringStartsWith('OK', $backup->fresh()->restore_result);
        $this->assertNull($service->issue());

        // Mauvaise clé ou fichier altéré : refus explicite
        config(['backup.key' => BackupService::generateKey()]);
        $this->expectExceptionMessage('Déchiffrement impossible');
        $service->decrypt($backup);
    }

    public function test_failure_is_logged_and_raises_an_alert(): void
    {
        config(['backup.mysqldump' => 'mysqldump-introuvable']);
        $backup = app(BackupService::class)->run();

        $this->assertSame('failed', $backup->status);
        $this->assertNotEmpty($backup->error);
        $pharmacyId = Pharmacy::first()->id;
        $this->assertStringContainsString('échec', app(AlertService::class)->backupIssue($pharmacyId));

        $this->actingAs($this->user('owner'));
        Livewire::test(AlertIndex::class)->assertSee('Dernière sauvegarde en échec');

        config(['backup.key' => null]);
        $this->assertStringContainsString('BACKUP_KEY', app(BackupService::class)->run()->error);
    }

    public function test_retention_keeps_recent_then_one_per_day(): void
    {
        File::ensureDirectoryExists(config('backup.path'));
        $make = function ($at) {
            $b = Backup::create(['status' => 'success', 'filename' => 'f-'.uniqid().'.enc', 'started_at' => $at, 'finished_at' => $at]);
            File::put(app(BackupService::class)->path($b), 'x');

            return $b;
        };
        $recent = $make(now()->subDays(2));
        $dayA1 = $make(now()->subDays(10)->setTime(10, 0));
        $dayA2 = $make(now()->subDays(10)->setTime(18, 0));
        $ancient = $make(now()->subDays(60));

        $this->assertSame(2, app(BackupService::class)->prune());
        $this->assertEqualsCanonicalizing([$recent->id, $dayA2->id], Backup::pluck('id')->all());
        $this->assertFileDoesNotExist(app(BackupService::class)->path($dayA1));
        $this->assertSame(0, $ancient->newQuery()->whereKey($ancient->id)->count());
    }

    public function test_screen_and_permissions(): void
    {
        // SaaS : base commune à toutes les pharmacies, sauvegardée par l'exploitant seul
        $this->actingAs(User::where('is_super_admin', true)->firstOrFail());
        Livewire::test(BackupIndex::class)->call('backupNow')->assertHasNoErrors()->assertSee('Réussie');
        $backup = Backup::firstOrFail();
        Livewire::test(BackupIndex::class)->call('testRestore', $backup->id)->assertHasNoErrors();
        $this->assertNotNull($backup->fresh()->restore_tested_at);

        foreach (['owner', 'cashier'] as $username) {
            $this->actingAs($this->user($username));
            $this->get(route('backups.index'))->assertForbidden();
        }
    }
}
