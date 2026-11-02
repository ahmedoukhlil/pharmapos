<?php

namespace Tests\Feature;

use App\Livewire\Setup\SetupWizard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Premier démarrage du SaaS sur une base vide : création du super-administrateur.
 */
class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    /** Base vide pour ce test ; le test suivant re-migre et re-sème les données de démonstration. */
    protected function refreshTestDatabase()
    {
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = false;
        $this->beginDatabaseTransaction();
    }

    public function test_fresh_install_creates_the_super_admin(): void
    {
        cache()->forget(\App\Services\Settings\PharmacySetupService::INSTALLED_KEY);
        $this->get(route('login'))->assertRedirect(route('setup'));
        // Les requêtes Livewire de l'assistant ne doivent pas être renvoyées vers /installation
        $this->assertNotSame(route('setup'), $this->post(route('default.livewire.update'))->headers->get('Location'));

        Livewire::test(SetupWizard::class)
            ->set('admin.name', 'Exploitant')->set('admin.username', 'Root')
            ->set('admin.password', 'court1')->set('admin.password_confirmation', 'court1')
            ->call('install')->assertHasErrors('admin.password')
            ->set('admin.password', 'Exploit2026')->set('admin.password_confirmation', 'Exploit2026')
            ->call('install')->assertRedirect(route('admin.pharmacies'));

        $admin = User::sole();
        $this->assertSame('root', $admin->username);
        $this->assertTrue($admin->is_super_admin);
        $this->assertNull($admin->pharmacy_id);
        $this->assertAuthenticatedAs($admin);

        // Une fois installé, l'assistant n'est plus accessible
        $this->post(route('logout'));
        $this->get(route('setup'))->assertNotFound();
    }
}
