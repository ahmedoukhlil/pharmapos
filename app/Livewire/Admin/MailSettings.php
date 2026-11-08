<?php

namespace App\Livewire\Admin;

use App\Mail\PlatformNotice;
use App\Models\PlatformSetting;
use App\Support\PlatformMail;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Console SaaS : serveur d'envoi des e-mails (SMTP) et notifications automatiques
 * (alertes de stock, échéance des abonnements). Le mot de passe n'est jamais réaffiché.
 */
#[Layout('components.layouts.app')]
#[Title('E-mails - PharmaPOS Admin')]
class MailSettings extends Component
{
    public array $mail = ['host' => '', 'port' => '587', 'encryption' => 'tls', 'username' => '', 'password' => '', 'from_address' => '', 'from_name' => 'PharmaPOS'];

    public array $notify = ['stock' => true, 'subscription' => true, 'subscription_days' => '15,7,3,1', 'admin_copy' => ''];

    public string $testTo = '';

    public ?string $message = null;

    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        foreach (PlatformMail::KEYS as $k) {
            if ($k !== 'password') {
                $this->mail[$k] = PlatformMail::get($k, $this->mail[$k]) ?? '';
            }
        }
        $this->notify = [
            'stock' => PlatformMail::notify('stock', '1') === '1',
            'subscription' => PlatformMail::notify('subscription', '1') === '1',
            'subscription_days' => implode(',', PlatformMail::subscriptionDays()),
            'admin_copy' => PlatformMail::notify('admin_copy', '') ?? '',
        ];
        $this->testTo = auth()->user()->email ?? '';
    }

    public function save(): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        $this->message = $this->error = null;
        $this->validate([
            'mail.host' => ['required', 'string', 'max:190'],
            'mail.port' => ['required', 'integer', 'between:1,65535'],
            'mail.encryption' => ['required', 'in:tls,ssl,none'],
            'mail.username' => ['nullable', 'string', 'max:190'],
            'mail.password' => ['nullable', 'string', 'max:300'],
            'mail.from_address' => ['required', 'email', 'max:190'],
            'mail.from_name' => ['required', 'string', 'max:100'],
            'notify.subscription_days' => ['nullable', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
            'notify.admin_copy' => ['nullable', 'email', 'max:190'],
        ], ['notify.subscription_days.regex' => 'Indiquez des nombres de jours séparés par des virgules, ex. 15,7,3,1.'], [
            'mail.host' => 'serveur SMTP', 'mail.port' => 'port', 'mail.from_address' => "adresse d'envoi", 'mail.from_name' => "nom d'expéditeur",
            'notify.admin_copy' => 'adresse en copie',
        ]);

        foreach (PlatformMail::KEYS as $k) {
            if ($k === 'password' && $this->mail['password'] === '') {
                continue;   // vide = on garde le mot de passe enregistré
            }
            PlatformSetting::put('mail.'.$k, (string) $this->mail[$k]);
        }
        PlatformSetting::put('notify.stock', $this->notify['stock'] ? '1' : '0');
        PlatformSetting::put('notify.subscription', $this->notify['subscription'] ? '1' : '0');
        PlatformSetting::put('notify.subscription_days', preg_replace('/\s+/', '', (string) $this->notify['subscription_days']) ?: '15,7,3,1');
        PlatformSetting::put('notify.admin_copy', (string) $this->notify['admin_copy']);
        logger()->info('platform.mail_settings_updated', ['user' => auth()->user()->username, 'host' => $this->mail['host']]);

        $this->mail['password'] = '';
        $this->message = 'Réglages enregistrés.';
    }

    /** Envoie un e-mail de test avec les réglages enregistrés. */
    public function sendTest(): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        $this->message = $this->error = null;
        $this->validate(['testTo' => ['required', 'email']], [], ['testTo' => 'adresse de test']);
        if (! PlatformMail::configured()) {
            $this->error = "Enregistrez d'abord le serveur SMTP et l'adresse d'envoi.";

            return;
        }
        try {
            PlatformMail::apply();
            Mail::to($this->testTo)->send(new PlatformNotice('Test PharmaPOS', 'E-mail de test',
                "Si vous lisez ce message, l'envoi des e-mails de PharmaPOS fonctionne."));
            $this->message = "E-mail de test envoyé à {$this->testTo}. Vérifiez aussi les indésirables.";
        } catch (\Throwable $e) {
            $this->error = "Échec de l'envoi : ".$e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.admin.mail-settings', [
            'hasPassword' => filled(PlatformMail::get('password')),
            'configured' => PlatformMail::configured(),
        ]);
    }
}
