<?php

namespace Tests\Feature;

use App\Livewire\Settings\UserIndex;
use App\Models\User;
use App\Services\Settings\UserService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Droits par utilisateur : ajout ou retrait par rapport au rôle, et garde-fous. */
class UserPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    public function test_owner_adds_and_removes_rights_of_a_cashier(): void
    {
        $cashier = $this->user('cashier');
        $this->assertFalse($cashier->hasPermission('stock.transfer'));
        $this->assertTrue($cashier->hasPermission('sale.hold'));

        $this->actingAs($this->user('owner'))->withFreshReauth();
        Livewire::test(UserIndex::class)->call('edit', $cashier->id)
            ->assertSet('form.permissions.sale__hold', true)
            ->set('form.permissions.stock__transfer', true)
            ->set('form.permissions.sale__hold', false)
            ->assertSee('ajouté')->assertSee('retiré')
            ->call('save')->assertHasNoErrors()
            ->assertSee('droits personnalisés');

        $cashier = $cashier->fresh();
        $this->assertTrue($cashier->hasPermission('stock.transfer'));
        $this->assertFalse($cashier->hasPermission('sale.hold'));
        $this->actingAs($cashier)->get(route('transfers'))->assertOk();

        // Changer de rôle recoche les droits du nouveau rôle
        $this->actingAs($this->user('owner'));
        Livewire::test(UserIndex::class)->call('edit', $cashier->id)
            ->set('form.role', 'cashier')->assertSet('form.permissions.stock__transfer', false)
            ->call('save')->assertHasNoErrors();
        $this->assertFalse($cashier->fresh()->hasPermission('stock.transfer'));
    }

    public function test_guards(): void
    {
        $service = app(UserService::class);
        $owner = $this->user('owner');
        $all = fn (User $u) => collect($service->editablePermissions())->mapWithKeys(fn ($l, $s) => [$s => $u->hasPermission($s)])->all();

        // On ne modifie pas ses propres droits
        $data = ['name' => $owner->name, 'username' => $owner->username, 'role' => 'owner', 'permissions' => ['user.manage' => false] + $all($owner)];
        $data['permissions']['user.manage'] = false;
        try {
            $service->update($owner, $owner, $data);
            $this->fail('Refus attendu.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('permissions', $e->errors());
        }

        // On ne donne pas un droit que l'on n'a pas
        $manager = User::factory()->create(['pharmacy_id' => $owner->pharmacy_id]);
        $manager->roleAssignments()->create(['role_id' => \App\Models\Role::firstWhere('slug', 'cashier')->id, 'pharmacy_id' => $owner->pharmacy_id]);
        \Illuminate\Support\Facades\DB::table('permission_user')->insert(['user_id' => $manager->id, 'pharmacy_id' => $owner->pharmacy_id,
            'permission_id' => \App\Models\Permission::firstWhere('slug', 'user.manage')->id, 'granted' => true]);
        $this->assertTrue($manager->hasPermission('user.manage'));

        $cashier = $this->user('cashier');
        $perms = $all($cashier);
        $perms['settings.manage'] = true;
        try {
            $service->update($manager, $cashier, ['name' => $cashier->name, 'username' => $cashier->username, 'role' => 'cashier', 'permissions' => $perms]);
            $this->fail('Refus attendu.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Gérer les paramètres', $e->errors()['permissions'][0]);
        }
    }
}
