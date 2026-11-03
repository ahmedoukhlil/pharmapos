<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use App\Livewire\Admin\PharmacyCreate;
use App\Livewire\Admin\PharmacyIndex;
use App\Livewire\Admin\PlatformSettings;
use App\Models\AuditLog;
use App\Models\Pharmacy;
use App\Models\PlatformSetting;
use App\Models\Register;
use App\Models\User;
use App\Services\Admin\TenantService;
use App\Services\Settings\UserService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SaaS : console du super-administrateur, connexion par code pharmacie, abonnement et suspension.
 */
class SaasAdminTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->whereNull('pharmacy_id')->firstOrFail();
    }

    /** @return array{0: Pharmacy, 1: User, 2: string} */
    private function newPharmacy(string $name = 'Pharmacie Ennour', ?string $code = 'ENNOUR', ?string $endsAt = null): array
    {
        return app(TenantService::class)->create($this->admin(),
            ['name' => $name, 'code' => $code, 'city' => 'Nouakchott', 'plan' => 'Standard', 'subscription_ends_at' => $endsAt],
            ['Caisse 1'], ['CASH' => true, 'BANKILY' => true], ['name' => 'Dr Sidi', 'username' => 'owner']);
    }

    public function test_super_admin_creates_a_pharmacy_with_temporary_owner_password(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(PharmacyCreate::class)
            ->set('pharmacy.name', 'Pharmacie Ennour')->set('pharmacy.code', 'demo01')->call('next')->assertHasErrors('pharmacy.code')
            ->set('pharmacy.code', 'ennour')->call('next')->assertSet('step', 2)
            ->set('owner.name', 'Dr Sidi')->set('owner.username', 'owner')->call('next')->assertSet('step', 3)
            ->call('addRegister')->call('next')->assertSet('step', 4)
            ->call('create')->assertSee('ENNOUR')->assertSee('Mot de passe provisoire');

        $p = Pharmacy::where('code', 'ENNOUR')->sole();
        $owner = User::where('pharmacy_id', $p->id)->sole();
        $this->assertSame('owner', $owner->username);   // même identifiant que dans la pharmacie de démonstration
        $this->assertTrue($owner->must_change_password);
        $this->assertSame('owner', app(UserService::class)->currentRole($owner)->slug);
        $this->assertSame(2, Register::where('pharmacy_id', $p->id)->count());
        $this->assertSame(today()->addMonth()->toDateString(), $p->subscription_ends_at->toDateString());
        $this->assertTrue(AuditLog::where('pharmacy_id', $p->id)->where('action', 'pharmacy.created')->exists());

        Livewire::test(PharmacyIndex::class)->assertSee('Pharmacie Ennour')->assertSee('Pharmacie de Démonstration');
    }

    public function test_login_with_pharmacy_code_remembers_it(): void
    {
        [$p, , $password] = $this->newPharmacy();

        // Sans code, plusieurs pharmacies : identifiant ambigu
        $this->post(route('login'), ['username' => 'owner', 'password' => $password])->assertSessionHasErrors('username');
        $this->assertGuest();

        $this->post(route('login'), ['pharmacy' => 'ennour', 'username' => 'OWNER', 'password' => $password])
            ->assertRedirect(route('pos'))->assertCookie(LoginController::COOKIE, 'ENNOUR');
        $this->assertSame($p->id, auth()->user()->pharmacy_id);
        $this->post(route('logout'));

        // Même identifiant, autre pharmacie, autre mot de passe
        $this->post(route('login'), ['pharmacy' => 'DEMO01', 'username' => 'owner', 'password' => 'password'])->assertRedirect(route('pos'));
        $this->assertSame(Pharmacy::where('code', 'DEMO01')->value('id'), auth()->user()->pharmacy_id);
        $this->post(route('logout'));

        $this->withCookie(LoginController::COOKIE, 'ENNOUR')->get(route('login'))->assertSee('value="ENNOUR"', false);
    }

    public function test_super_admin_logs_in_without_code_and_stays_in_the_console(): void
    {
        $this->newPharmacy();
        $this->post(route('login'), ['username' => 'admin', 'password' => 'password'])->assertRedirect(route('admin.pharmacies'));
        $this->get(route('pos'))->assertRedirect(route('admin.pharmacies'));
        $this->get(route('admin.pharmacies'))->assertOk()->assertSee('Pharmacie Ennour');
        $this->get(route('backups.index'))->assertOk();

        $this->post(route('logout'));
        $this->actingAs(User::where('username', 'owner')->whereHas('pharmacy', fn ($q) => $q->where('code', 'DEMO01'))->first());
        $this->get(route('admin.pharmacies'))->assertForbidden();
        $this->get(route('backups.index'))->assertForbidden();
    }

    public function test_suspension_and_expiry_block_access(): void
    {
        [$p, $owner] = $this->newPharmacy();
        $owner->forceFill(['password' => 'Ennour2026', 'must_change_password' => false])->save();
        $tenants = app(TenantService::class);

        $this->actingAs($owner);
        $this->get(route('pos'))->assertOk();

        $this->actingAs($this->admin());
        Livewire::test(PharmacyIndex::class)->call('edit', $p->id, 'suspend')->call('save')->assertHasErrors('reason')
            ->set('form.reason', 'Facture impayée')->call('save')->assertSee('Suspendue');
        $this->assertFalse($p->fresh()->is_active);

        // Session en cours : déconnecté à la requête suivante ; nouvelle connexion refusée
        $this->actingAs($owner);
        $this->get(route('pos'))->assertRedirect(route('login'))->assertSessionHas('blocked');
        $this->assertGuest();
        $this->post(route('login'), ['pharmacy' => 'ENNOUR', 'username' => 'owner', 'password' => 'Ennour2026'])
            ->assertSessionHasErrors(['username' => LoginController::blockedMessage($p->fresh())]);

        $tenants->reactivate($this->admin(), $p->fresh());
        $tenants->setSubscription($this->admin(), $p->fresh(), 'Standard', today()->subDay()->toDateString());
        $this->post(route('login'), ['pharmacy' => 'ENNOUR', 'username' => 'owner', 'password' => 'Ennour2026'])->assertSessionHasErrors('username');
        $this->assertGuest();

        // +1 mois depuis aujourd'hui quand l'échéance est passée
        $tenants->extend($this->admin(), $p->fresh(), 1);
        $this->assertSame(today()->addMonth()->toDateString(), $p->fresh()->subscription_ends_at->toDateString());
        $this->post(route('login'), ['pharmacy' => 'ENNOUR', 'username' => 'owner', 'password' => 'Ennour2026'])->assertRedirect(route('pos'));

        // Pharmacie du jour d'échéance : encore accessible
        $tenants->setSubscription($this->admin(), $p->fresh(), null, today()->toDateString());
        $this->assertTrue($p->fresh()->isAccessible());
        $this->assertSame(['pharmacy.reactivated', 'pharmacy.subscription', 'pharmacy.suspended'],
            AuditLog::where('pharmacy_id', $p->id)->whereIn('action', ['pharmacy.suspended', 'pharmacy.reactivated', 'pharmacy.subscription'])
                ->distinct()->orderBy('action')->pluck('action')->all());
    }

    public function test_reset_owner_password_and_guards(): void
    {
        [$p, $owner] = $this->newPharmacy();
        $this->actingAs($this->admin());

        Livewire::test(PharmacyIndex::class)->call('resetOwner', $p->id)->assertSee('Nouveau mot de passe provisoire');
        $this->assertTrue($owner->fresh()->must_change_password);
        $this->assertTrue(AuditLog::where('pharmacy_id', $p->id)->where('action', 'pharmacy.owner_password_reset')->exists());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TenantService::class)->suspend($owner, $p, 'test');
    }
    public function test_super_admin_resets_any_user_password(): void
    {
        [$p, $owner] = $this->newPharmacy();
        $cashier = User::create(["pharmacy_id" => $p->id, "name" => "Caissier", "username" => "caisse1", "password" => "Caisse2026x", "is_active" => false]);
        $this->actingAs($this->admin());

        Livewire::test(PharmacyIndex::class)->call("edit", $p->id, "users")->assertSee("caisse1")->assertSee("Dr Sidi")
            ->call("resetUser", $cashier->id)->assertSee("Nouveau mot de passe provisoire")->assertSee("caisse1");
        $this->assertTrue($cashier->fresh()->must_change_password);
        $this->assertTrue($cashier->fresh()->is_active);
        $this->assertTrue(AuditLog::where("pharmacy_id", $p->id)->where("action", "pharmacy.user_password_reset")->exists());
    }

    public function test_platform_settings_store_encrypted_key_and_model(): void
    {
        config(["services.anthropic.key" => null]);
        $this->actingAs($this->admin());
        $this->get(route("admin.settings"))->assertOk()->assertSee("aucune");

        Livewire::test(PlatformSettings::class)->set("apiKey", "mauvaise")->call("save")->assertHasErrors("apiKey")
            ->set("apiKey", "sk-ant-api03-secretABCD")->set("model", "claude-sonnet-5-5")->call("save")
            ->assertHasNoErrors()->assertSee("sk-ant-…ABCD")->assertDontSee("secretABCD");

        $this->assertSame("sk-ant-api03-secretABCD", PlatformSetting::anthropicKey());
        $this->assertSame("claude-sonnet-5-5", PlatformSetting::anthropicModel());
        $this->assertStringNotContainsString("secret", (string) PlatformSetting::find(PlatformSetting::ANTHROPIC_KEY)->getRawOriginal("value"));

        Livewire::test(PlatformSettings::class)->call("removeKey");
        $this->assertNull(PlatformSetting::anthropicKey());

        $this->actingAs(User::where("username", "owner")->firstOrFail());
        $this->get(route("admin.settings"))->assertForbidden();
    }
}
