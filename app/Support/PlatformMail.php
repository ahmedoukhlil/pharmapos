<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Serveur d'envoi des e-mails réglé dans la console SaaS (Admin → E-mails).
 * Appliqué juste avant un envoi ; sans réglage, la configuration du .env reste en place.
 */
class PlatformMail
{
    public const KEYS = ['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return PlatformSetting::get('mail.'.$key, $default);
    }

    public static function configured(): bool
    {
        return filled(self::get('host')) && filled(self::get('from_address'));
    }

    public static function apply(): void
    {
        if (! self::configured()) {
            return;
        }
        $encryption = self::get('encryption', 'tls');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => self::get('host'),
            'mail.mailers.smtp.port' => (int) self::get('port', $encryption === 'ssl' ? '465' : '587'),
            // ssl = connexion chiffrée dès le départ (465) ; tls = STARTTLS (587)
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => self::get('username'),
            'mail.mailers.smtp.password' => self::get('password'),
            'mail.from.address' => self::get('from_address'),
            'mail.from.name' => self::get('from_name', 'PharmaPOS'),
        ]);
        app('mail.manager')->forgetMailers();
    }

    /** Réglage d'une notification : stock, subscription, admin_copy, subscription_days */
    public static function notify(string $key, ?string $default = null): ?string
    {
        return PlatformSetting::get('notify.'.$key, $default);
    }

    /** @return int[] jours avant échéance de l'abonnement où l'on prévient, ex. [15, 7, 3, 1] */
    public static function subscriptionDays(): array
    {
        return collect(explode(',', (string) self::notify('subscription_days', '15,7,3,1')))
            ->map(fn ($d) => (int) trim($d))->filter(fn ($d) => $d > 0)->unique()->sortDesc()->values()->all();
    }
}
