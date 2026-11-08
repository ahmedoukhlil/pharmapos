<?php

namespace App\Services\Notifications;

use App\Mail\PlatformNotice;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Alerts\AlertService;
use App\Support\PlatformMail;
use Illuminate\Support\Facades\Mail;

/**
 * E-mails automatiques envoyés chaque matin (pharmapos:notify) :
 * - alertes de stock (seuils atteints, lots périmés ou proches de la péremption) ;
 * - échéance de l'abonnement (J-15, J-7, J-3, J-1 par défaut, puis le jour où il expire).
 * Destinataires : propriétaires et pharmaciens responsables ayant une adresse e-mail.
 */
class NotificationService
{
    private const MAX_LINES = 25;

    public function __construct(private AlertService $alerts) {}

    /** @return array{stock: int, subscription: int} nombre d'e-mails envoyés */
    public function run(): array
    {
        $sent = ['stock' => 0, 'subscription' => 0];
        if (! PlatformMail::configured()) {
            return $sent;
        }
        PlatformMail::apply();

        foreach (Pharmacy::where('is_active', true)->get() as $pharmacy) {
            $to = $this->recipients($pharmacy);
            if (PlatformMail::notify('subscription', '1') === '1' && $this->subscription($pharmacy, $to)) {
                $sent['subscription']++;
            }
            if ($to && ! $pharmacy->subscriptionExpired() && PlatformMail::notify('stock', '1') === '1' && $this->stock($pharmacy, $to)) {
                $sent['stock']++;
            }
        }

        return $sent;
    }

    /** @return string[] */
    public function recipients(Pharmacy $pharmacy): array
    {
        return User::where('pharmacy_id', $pharmacy->id)->where('is_active', true)->whereNotNull('email')
            ->whereHas('roleAssignments', fn ($q) => $q->where('pharmacy_id', $pharmacy->id)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['owner', 'pharmacist_in_charge'])))
            ->pluck('email')->unique()->values()->all();
    }

    private function stock(Pharmacy $pharmacy, array $to): bool
    {
        $lines = [];
        foreach ($this->alerts->lowStock($pharmacy->id, 100) as $p) {
            $lines[] = "Stock bas : {$p->medicine->brand_name} {$p->medicine->strength} {$p->label} - reste ".(int) $p->sellable_quantity." (seuil {$p->reorder_threshold})";
        }
        foreach ($this->alerts->expiring($pharmacy->id)->where('days_left', '<=', 30) as $e) {
            $name = trim("{$e->presentation->medicine->brand_name} {$e->presentation->medicine->strength}");
            $lines[] = $e->days_left < 0
                ? "Périmé : {$name}, lot {$e->batch->lot_number} ({$e->quantity} unités) - à retirer de la vente"
                : "Péremption dans {$e->days_left} j : {$name}, lot {$e->batch->lot_number} ({$e->quantity} unités)";
        }
        if (! $lines) {
            return false;
        }
        $extra = count($lines) - self::MAX_LINES;
        $lines = array_slice($lines, 0, self::MAX_LINES);
        if ($extra > 0) {
            $lines[] = "… et {$extra} autre(s) alerte(s).";
        }

        Mail::to($to)->send(new PlatformNotice(
            "Alertes de stock - {$pharmacy->name}",
            'Alertes de stock du jour',
            "Voici les produits à surveiller aujourd'hui à {$pharmacy->name}.",
            $lines, url('/alertes'), 'Voir les alertes',
        ));

        return true;
    }

    private function subscription(Pharmacy $pharmacy, array $to): bool
    {
        if (! $pharmacy->subscription_ends_at) {
            return false;
        }
        $days = (int) today()->diffInDays($pharmacy->subscription_ends_at, false);
        $end = $pharmacy->subscription_ends_at->format('d/m/Y');
        if (in_array($days, PlatformMail::subscriptionDays(), true)) {
            [$subject, $title, $intro] = ["Votre abonnement PharmaPOS expire dans {$days} jour(s)", "Abonnement : échéance le {$end}",
                "L'abonnement de {$pharmacy->name} arrive à échéance le {$end}. Pensez à le renouveler pour continuer à utiliser PharmaPOS sans interruption."];
        } elseif ($days === -1) {
            [$subject, $title, $intro] = ['Votre abonnement PharmaPOS a expiré', 'Abonnement expiré',
                "L'abonnement de {$pharmacy->name} a expiré le {$end} : l'accès à PharmaPOS est suspendu. Contactez-nous pour le renouveler."];
        } else {
            return false;
        }

        $copy = PlatformMail::notify('admin_copy');
        $recipients = $to ?: array_filter([$copy]);
        if (! $recipients) {
            return false;
        }
        $mail = Mail::to($recipients);
        if ($copy && $to) {
            $mail->bcc($copy);
        }
        $mail->send(new PlatformNotice($subject, $title, $intro, ["Pharmacie : {$pharmacy->name} ({$pharmacy->code})", "Échéance : {$end}"]));

        return true;
    }
}
