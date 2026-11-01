<?php

namespace Tests\Feature;

use App\Livewire\Pos\Checkout;
use App\Livewire\Settings\StoreLayoutIndex;
use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\Location;
use App\Models\Register;
use App\Models\User;
use App\Services\Settings\StoreLayoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Paramétrage des caisses et des emplacements, et leurs garde-fous.
 */
class StoreLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    public function test_add_register_and_location_from_screen(): void
    {
        $this->actingAs($this->user('owner'));
        $shelf = Location::where('code', 'RAY')->first();

        Livewire::test(StoreLayoutIndex::class)
            ->call('editRegister')->assertSet('form.name', 'Caisse 3')->set('form.printer_width', '58')->call('save')->assertHasNoErrors()
            ->call('editLocation')->set('form.name', 'Frigo')->set('form.type', 'bin')->call('save')->assertHasNoErrors()
            ->assertSee('Caisse 3')->assertSee('Frigo');

        $register = Register::where('name', 'Caisse 3')->sole();
        $this->assertSame('C03', $register->code);
        $this->assertSame($shelf->id, $register->location_id);
        $this->assertSame('58', $register->printer_width);
        $frigo = Location::where('name', 'Frigo')->sole();
        $this->assertTrue($frigo->is_sellable);
        $this->assertSame(2, AuditLog::whereIn('action', ['register.created', 'location.created'])->count());

        // Une nouvelle caisse apparaît en caisse ; désactivée, elle disparaît
        $this->assertContains('C03', Livewire::test(Checkout::class)->instance()->registers->pluck('code')->all());
        Livewire::test(StoreLayoutIndex::class)->call('toggleRegister', $register->id);
        $this->assertNotContains('C03', Livewire::test(Checkout::class)->instance()->registers->pluck('code')->all());
    }

    public function test_guards(): void
    {
        $owner = $this->user('owner');
        $service = app(StoreLayoutService::class);
        $c01 = Register::where('code', 'C01')->first();
        $shelf = Location::where('code', 'RAY')->first();
        $quarantine = Location::where('type', 'quarantine')->first();

        // Session ouverte
        CashSession::create(['pharmacy_id' => $c01->pharmacy_id, 'register_id' => $c01->id, 'opened_by' => $owner->id, 'opened_at' => now(), 'opening_float' => 0]);
        $this->assertThrows(fn () => $service->setRegisterActive($owner, $c01, false), ValidationException::class, 'session de caisse ouverte');

        // Dernière caisse active
        $service->setRegisterActive($owner, Register::where('code', 'C02')->first(), false);
        CashSession::query()->delete();
        $this->assertThrows(fn () => $service->setRegisterActive($owner, $c01, false), ValidationException::class, 'Au moins une caisse');

        // Emplacement avec stock, emplacement d'une caisse, quarantaine unique, quarantaine jamais vendable
        $this->assertThrows(fn () => $service->setLocationActive($owner, $shelf, false), ValidationException::class);
        $this->assertThrows(fn () => $service->saveLocation($owner, ['name' => $shelf->name, 'type' => 'shelf', 'is_sellable' => false], $shelf), ValidationException::class, 'Une caisse vend');
        $this->assertThrows(fn () => $service->setLocationActive($owner, $quarantine, false), ValidationException::class);
        $service->saveLocation($owner, ['name' => 'Quarantaine', 'type' => 'shelf', 'is_sellable' => true], $quarantine);
        $this->assertSame('quarantine', $quarantine->fresh()->type);
        $this->assertFalse($quarantine->fresh()->is_sellable);

        $this->actingAs($this->user('cashier'))->get(route('store-layout.index'))->assertForbidden();
    }
}
