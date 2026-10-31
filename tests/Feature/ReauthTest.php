<?php

namespace Tests\Feature;

use App\Livewire\Catalogue\ProductEditor;
use App\Livewire\Inventory\InventorySheet;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Presentation;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Réauthentification des opérations sensibles (PRD §4).
 */
class ReauthTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    /** Inventaire du paracétamol compté et soumis, prêt à être approuvé. */
    private function inventoryInReview()
    {
        $service = app(InventoryService::class);
        $store = $this->user('storekeeper');
        $session = $service->open($store, [
            'scope' => 'partial',
            'location_id' => Location::where('code', 'RAY')->value('id'),
            'presentation_ids' => [Presentation::where('sku', 'PARA500-16')->value('id')],
        ]);
        foreach ($session->lines as $line) {
            $service->count($store, $session, ['line_id' => $line->id, 'quantity' => 1]);
        }
        $service->submit($store, $session);

        return $session->fresh();
    }

    public function test_sensitive_action_waits_for_password_then_runs(): void
    {
        $session = $this->inventoryInReview();
        $this->actingAs($this->user('pharmacist_in_charge'));

        $sheet = Livewire::test(InventorySheet::class, ['session' => $session])
            ->call('approve')
            ->assertSee('Confirmez votre identité')
            ->assertSee('Approuver un inventaire');
        $this->assertSame('review', $session->fresh()->status);

        $sheet->set('reauthPassword', 'mauvais')->call('confirmReauth')
            ->assertHasErrors('reauthPassword')->assertSee('Confirmez votre identité');
        $this->assertSame(1, AuditLog::where('action', 'reauth.failed')->count());

        $sheet->set('reauthPassword', PharmacySeeder::DEMO_PASSWORD)->call('confirmReauth')
            ->assertHasNoErrors()->assertDontSee('Confirmez votre identité')->assertSee('stock ajusté');
        $this->assertSame('approved', $session->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'reauth.confirmed')->count());
    }

    public function test_confirmation_expires_and_pending_action_cannot_be_forged(): void
    {
        $this->actingAs($this->user('pharmacist_in_charge'));
        $this->withFreshReauth();

        $this->travel(6)->minutes();
        Livewire::test(InventorySheet::class, ['session' => $this->inventoryInReview()])
            ->call('approve')->assertSee('Confirmez votre identité');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(InventorySheet::class, ['session' => $this->inventoryInReview()])
            ->set('reauth', ['permission' => 'x', 'label' => 'x', 'method' => 'cancel', 'args' => []]);
    }

    public function test_too_many_wrong_passwords_are_throttled(): void
    {
        $this->actingAs($this->user('pharmacist_in_charge'));
        $sheet = Livewire::test(InventorySheet::class, ['session' => $this->inventoryInReview()])->call('approve');
        for ($i = 0; $i < 5; $i++) {
            $sheet->set('reauthPassword', 'faux')->call('confirmReauth');
        }

        $sheet->set('reauthPassword', PharmacySeeder::DEMO_PASSWORD)->call('confirmReauth')->assertSee('Trop de tentatives');
    }

    public function test_only_price_changes_require_reauth_in_catalogue(): void
    {
        $this->actingAs($this->user('owner'));
        $para = Presentation::where('sku', 'PARA500-16')->first();

        $editor = Livewire::test(ProductEditor::class, ['medicine' => $para->medicine])
            ->call('editPresentation', $para->id)
            ->set('pres.label', 'Boîte de 16 cp')
            ->call('savePresentation')
            ->assertDontSee('Confirmez votre identité');
        $this->assertSame('Boîte de 16 cp', $para->fresh()->label);

        $editor->call('editPresentation', $para->id)->set('pres.sale_price', '199')
            ->call('savePresentation')->assertSee('Modifier les prix');
        $this->assertSame('180.00', $para->fresh()->sale_price);

        $editor->set('reauthPassword', PharmacySeeder::DEMO_PASSWORD)->call('confirmReauth');
        $this->assertSame('199.00', $para->fresh()->sale_price);
    }
}
