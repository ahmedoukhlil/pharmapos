<?php

namespace Tests\Feature;

use App\Livewire\Pos\Checkout;
use App\Livewire\Scanner\ScannerPanel;
use App\Models\CashSession;
use App\Models\Presentation;
use App\Models\Register;
use App\Models\ScanEvent;
use App\Models\ScannerSession;
use App\Models\User;
use App\Services\Scanner\ScannerException;
use App\Services\Scanner\ScannerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Scanner smartphone (PRD §8, mode A) : appairage QR + confirmation, SCN-04/05/06.
 */
class ScannerTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private ScannerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ScannerService::class);
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function register(): Register
    {
        return Register::where('code', 'C01')->firstOrFail();
    }

    /** Session de scanner active pour le téléphone du préparateur. */
    private function activeScanner(): ScannerSession
    {
        [$session, $token] = $this->service->createPairing($this->user('cashier'), 'pos', $this->register()->id);
        $this->service->claim($this->user('assistant_pharmacist'), $token, 'Android');

        return $this->service->confirm($this->user('cashier'), $session->fresh());
    }

    private function scan(ScannerSession $session, int $seq, string $code, ?string $id = null): array
    {
        return $this->service->ingest($this->user('assistant_pharmacist'), $session->fresh(),
            ['id' => $id ?? (string) Str::uuid(), 'sequence' => $seq, 'barcode' => $code]);
    }

    public function test_pairing_requires_single_use_token_and_cashier_confirmation(): void
    {
        [$session, $token] = $this->service->createPairing($this->user('cashier'), 'pos', $this->register()->id);
        $this->assertSame('waiting_phone', ScannerService::state($session));
        $this->assertNotSame($token, $session->pairing_token_hash);   // seul le hash est stocké

        $this->service->claim($this->user('assistant_pharmacist'), $token, 'Android');
        $this->assertSame('awaiting_confirmation', ScannerService::state($session->fresh()));

        // Scans refusés tant que le caissier n'a pas confirmé
        try {
            $this->scan($session, 1, '2000000000015');
            $this->fail('Scan avant confirmation accepté');
        } catch (ScannerException $e) {
            $this->assertSame('session_inactive', $e->reason);
        }

        // Le QR ne sert qu'une fois
        try {
            $this->service->claim($this->user('cashier'), $token, 'Autre');
            $this->fail('QR réutilisé');
        } catch (ScannerException $e) {
            $this->assertSame('token_used', $e->reason);
        }

        $this->service->confirm($this->user('cashier'), $session->fresh());
        $this->assertSame('active', ScannerService::state($session->fresh()));
    }

    public function test_expired_qr_is_refused(): void
    {
        [, $token] = $this->service->createPairing($this->user('cashier'), 'pos', $this->register()->id);
        $this->travel(3)->minutes();

        $this->expectException(ScannerException::class);
        $this->service->claim($this->user('assistant_pharmacist'), $token, 'Android');
    }

    public function test_scans_are_acknowledged_deduplicated_and_pulled_once(): void
    {
        $session = $this->activeScanner();
        $id = (string) Str::uuid();

        $ack = $this->scan($session, 1, '2000000000015', $id);
        $this->assertSame('received', $ack['status']);
        $this->assertStringContainsString('Parac', $ack['product']);

        // Renvoi réseau du même scan : même accusé, pas de doublon (SCN-05)
        $this->assertTrue($this->scan($session, 1, '2000000000015', $id)['duplicate']);
        $this->assertSame('unknown_product', $this->scan($session, 2, '9999999999994')['status']);
        $this->scan($session, 3, '2000000000015');   // scan intentionnel répété (SCN-04)

        $this->assertSame(3, ScanEvent::count());
        $this->assertCount(3, $this->service->pull($session));
        $this->assertCount(0, $this->service->pull($session));   // jamais appliqués deux fois
    }

    public function test_revoked_or_foreign_phone_cannot_scan(): void
    {
        $session = $this->activeScanner();

        $this->actingAs($this->user('cashier'));
        $this->postJson(route('scanner.scan', $session), ['id' => (string) Str::uuid(), 'sequence' => 1, 'barcode' => '2000000000015'])
            ->assertNotFound();

        $this->service->revoke($this->user('cashier'), $session);
        $this->actingAs($this->user('assistant_pharmacist'));
        $this->postJson(route('scanner.scan', $session), ['id' => (string) Str::uuid(), 'sequence' => 1, 'barcode' => '2000000000015'])
            ->assertStatus(410)->assertJson(['error' => 'session_inactive']);
    }

    public function test_end_to_end_qr_phone_and_checkout(): void
    {
        $register = $this->register();
        CashSession::create(['pharmacy_id' => $register->pharmacy_id, 'register_id' => $register->id,
            'opened_by' => $this->user('cashier')->id, 'opened_at' => now(), 'opening_float' => 0]);

        // PC : génère le QR
        $this->actingAs($this->user('cashier'));
        $panel = Livewire::test(ScannerPanel::class, ['mode' => 'pos', 'contextId' => $register->id])
            ->call('start')->assertSee('Scannez ce QR')->assertSeeHtml('<svg');
        $pairingUrl = $panel->get('pairingUrl');

        // Téléphone : lit le QR (connecté avec son propre compte)
        $this->actingAs($this->user('assistant_pharmacist'));
        $this->get($pairingUrl)->assertRedirect();
        $session = ScannerSession::latest()->first();
        $this->get(route('scanner.show', $session))->assertOk()->assertSee('Confirmez l');

        // PC : confirme
        $this->actingAs($this->user('cashier'));
        $panel->call('poll')->assertSee('Téléphone à confirmer')->call('confirm');
        $this->assertSame('active', ScannerService::state($session->fresh()));

        // Téléphone : scanne deux fois le paracétamol
        $this->actingAs($this->user('assistant_pharmacist'));
        foreach ([1, 2] as $seq) {
            $this->postJson(route('scanner.scan', $session), ['id' => (string) Str::uuid(), 'sequence' => $seq, 'barcode' => '2000000000015'])
                ->assertOk()->assertJson(['status' => 'received']);
        }

        // PC : la relève transmet les scans au panier
        $this->actingAs($this->user('cashier'));
        $panel->call('poll')->assertDispatched('remote-scan');
        $para = Presentation::where('sku', 'PARA500-16')->value('id');
        Livewire::test(Checkout::class)
            ->call('addRemote', $para)->call('addRemote', $para)
            ->assertSet('cart.0.quantity', 2);
    }
}
