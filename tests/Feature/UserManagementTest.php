<?php

namespace Tests\Feature;

use App\Livewire\Settings\UserIndex;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Settings\UserService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gestion des utilisateurs : création avec mot de passe provisoire, changement forcé,
 * rôles, désactivation et garde-fous (soi-même, dernier propriétaire).
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    public function test_create_user_then_forced_password_change(): void
    {
        $this->actingAs($this->user('owner'))->withFreshReauth();
        $component = Livewire::test(UserIndex::class)->call('edit')
            ->set('form.name', 'Fatimetou Mint Ahmed')->set('form.username', 'Fatimetou')->set('form.role', 'cashier')
            ->call('save')->assertHasNoErrors()->assertSee('Mot de passe provisoire de Fatimetou Mint Ahmed');

        $password = $component->get('credentials')[2];
        $new = $this->user('fatimetou');
        $this->assertTrue($new->must_change_password);
        $this->assertSame('cashier', app(UserService::class)->currentRole($new)->slug);
        $this->assertSame(1, AuditLog::where('action', 'user.created')->count());

        // Connexion avec le mot de passe provisoire : toute page renvoie au changement
        auth()->logout();
        $this->post(route('login'), ['username' => 'fatimetou', 'password' => $password]);
        $this->get(route('pos'))->assertRedirect(route('password.edit'));
        $this->put(route('password.update'), ['current_password' => $password, 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertSessionHasErrors('password');
        $this->put(route('password.update'), ['current_password' => $password, 'password' => 'Nouakchott2026', 'password_confirmation' => 'Nouakchott2026'])
            ->assertRedirect(route('pos'));
        $this->assertFalse($new->fresh()->must_change_password);
        $this->get(route('pos'))->assertOk();
    }

    public function test_delete_only_unused_accounts(): void
    {
        $owner = $this->user('owner');
        $service = app(UserService::class);

        // Compte jamais utilisé : supprimé, rôle retiré, trace dans le journal
        [$new] = $service->create($owner, ['name' => 'Stagiaire', 'username' => 'stagiaire', 'role' => 'cashier']);
        $this->actingAs($owner)->withFreshReauth();
        Livewire::test(UserIndex::class)->call('delete', $new->id)->assertHasNoErrors();
        $this->assertNull(User::find($new->id));
        $this->assertEquals(['name' => 'Stagiaire', 'username' => 'stagiaire', 'email' => null, 'phone' => null, 'role' => 'cashier'],
            AuditLog::where('action', 'user.deleted')->sole()->old_values);

        // Compte avec historique (ici une ligne du journal d'audit) : refusé, il faut désactiver
        $cashier = $this->user('cashier');
        AuditLog::create(['pharmacy_id' => $cashier->pharmacy_id, 'user_id' => $cashier->id, 'action' => 'user.password_changed']);
        Livewire::test(UserIndex::class)->call('delete', $cashier->id)->assertHasErrors('user');
        $this->assertNotNull(User::find($cashier->id));

        $this->assertThrows(fn () => $service->delete($owner, $owner), ValidationException::class);
    }

    public function test_deactivate_reset_and_guards(): void
    {
        $owner = $this->user('owner');
        $service = app(UserService::class);
        $cashier = $this->user('cashier');

        $temp = $service->resetPassword($owner, $cashier);
        $this->assertTrue($cashier->fresh()->must_change_password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($temp, $cashier->fresh()->password));

        $service->setActive($owner, $cashier, false);
        $this->post(route('login'), ['username' => 'cashier', 'password' => $temp])->assertSessionHasErrors('username');
        $this->assertGuest();

        // Soi-même, dernier propriétaire, rôle propriétaire réservé aux propriétaires
        $this->assertThrows(fn () => $service->setActive($owner, $owner, false), ValidationException::class);
        $this->assertThrows(fn () => $service->update($owner, $owner, ['name' => 'X', 'username' => 'owner', 'role' => 'cashier']), ValidationException::class);
        $pharmacist = $this->user('pharmacist_in_charge');
        $this->assertFalse($service->assignableRoles($pharmacist)->contains('slug', 'owner'));
        $this->assertThrows(fn () => $service->setActive($pharmacist, $owner, false), ValidationException::class);
        $this->assertThrows(fn () => $service->create($owner, ['name' => 'Doublon', 'username' => 'owner', 'role' => 'cashier']), ValidationException::class);

        // Écran : réservé à user.manage
        $this->actingAs($this->user('assistant_pharmacist'))->get(route('users.index'))->assertForbidden();
    }
}
