<?php

namespace Tests\Feature;

use App\Livewire\Admin\MailSettings;
use App\Mail\PlatformNotice;
use App\Models\Pharmacy;
use App\Models\PlatformSetting;
use App\Models\Presentation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Console SaaS : réglage SMTP, e-mail de test, alertes de stock et d'abonnement du matin. */
class MailNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function configure(): void
    {
        $this->actingAs(User::where('is_super_admin', true)->firstOrFail());
        Livewire::test(MailSettings::class)
            ->set('mail.host', 'smtp.example.com')->set('mail.port', '587')->set('mail.username', 'bot')
            ->set('mail.password', 'secret-123')->set('mail.from_address', 'notif@example.com')
            ->set('notify.admin_copy', 'commercial@example.com')
            ->call('save')->assertHasNoErrors()->assertSee('Réglages enregistrés')
            ->assertSet('mail.password', '');
    }

    public function test_settings_saved_encrypted_and_test_mail_sent(): void
    {
        Mail::fake();
        $this->configure();
        $this->assertSame('secret-123', PlatformSetting::get('mail.password'));
        $this->assertStringNotContainsString('secret-123', (string) PlatformSetting::find('mail.password')->value);

        Livewire::test(MailSettings::class)->assertSee('Envoi configuré')
            ->set('testTo', 'moi@example.com')->call('sendTest')->assertSee('E-mail de test envoyé');
        Mail::assertSent(PlatformNotice::class, fn ($m) => $m->hasTo('moi@example.com'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));

        // Réservé au super-administrateur
        $this->actingAs(User::where('username', 'owner')->firstOrFail())->get(route('admin.mail'))->assertForbidden();
    }

    public function test_morning_notifications_stock_and_subscription(): void
    {
        Mail::fake();
        $this->configure();
        $owner = User::where('username', 'owner')->firstOrFail();
        $owner->update(['email' => 'owner@example.com']);
        Presentation::where('sku', 'PARA500-16')->update(['reorder_threshold' => 100000]);
        Pharmacy::whereKey($owner->pharmacy_id)->update(['subscription_ends_at' => today()->addDays(7)]);

        $this->artisan('pharmapos:notify')->assertSuccessful();

        Mail::assertSent(PlatformNotice::class, fn ($m) => $m->hasTo('owner@example.com') && str_contains($m->subjectLine, 'Alertes de stock')
            && collect($m->lines)->contains(fn ($l) => str_contains($l, 'Stock bas')));
        Mail::assertSent(PlatformNotice::class, fn ($m) => $m->hasTo('owner@example.com') && $m->hasBcc('commercial@example.com')
            && str_contains($m->subjectLine, 'expire dans 7 jour'));

        // Pas de rappel un jour hors de la liste
        Mail::fake();
        Pharmacy::whereKey($owner->pharmacy_id)->update(['subscription_ends_at' => today()->addDays(9)]);
        $this->artisan('pharmapos:notify')->assertSuccessful();
        Mail::assertNotSent(PlatformNotice::class, fn ($m) => str_contains($m->subjectLine, 'abonnement'));
    }
}
