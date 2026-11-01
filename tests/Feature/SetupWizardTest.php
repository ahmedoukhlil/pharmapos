<?php

namespace Tests\Feature;

use App\Livewire\Setup\SetupWizard;
use App\Models\PaymentMethod;
use App\Models\Pharmacy;
use App\Models\Register;
use App\Models\User;
use App\Services\Settings\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Assistant de premier démarrage sur une base vide (sans données de démonstration).
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

    public function test_fresh_install_creates_pharmacy_and_owner(): void
    {
        $this->get(route('login'))->assertRedirect(route('setup'));
        // Les requêtes Livewire de l'assistant ne doivent pas être renvoyées vers /installation
        $this->assertNotSame(route('setup'), $this->post(route('default.livewire.update'))->headers->get('Location'));

        Livewire::test(SetupWizard::class)
            ->call('next')->assertHasErrors('pharmacy.name')
            ->set('pharmacy.name', 'Pharmacie Ennour')->call('next')->assertSet('step', 2)
            ->set('owner.name', 'Dr Sidi')->set('owner.username', 'Sidi')
            ->set('owner.password', 'abc')->set('owner.password_confirmation', 'abc')->call('next')->assertHasErrors('owner.password')
            ->set('owner.password', 'Ennour2026')->set('owner.password_confirmation', 'Ennour2026')->call('next')->assertSet('step', 3)
            ->call('addRegister')->set('methods.CLICK', true)->set('methods.MASRVI', false)->call('next')->assertSet('step', 4)
            ->call('install')->assertRedirect(route('catalogue.import'));

        $pharmacy = Pharmacy::sole();
        $owner = User::where('username', 'sidi')->sole();
        $this->assertSame('PHARMACIEENNOUR', $pharmacy->code);
        $this->assertSame('owner', app(UserService::class)->currentRole($owner)->slug);
        $this->assertTrue($owner->hasPermission('user.manage'));
        $this->assertSame(['C01', 'C02'], Register::orderBy('code')->pluck('code')->all());
        $this->assertSame(['CASH', 'BANKILY', 'SEDAD', 'CLICK'], PaymentMethod::where('is_active', true)->orderBy('sort_order')->pluck('code')->all());
        $this->assertAuthenticatedAs($owner);

        // Une fois installé, l'assistant n'est plus accessible
        $this->get(route('setup'))->assertNotFound();
    }
}
