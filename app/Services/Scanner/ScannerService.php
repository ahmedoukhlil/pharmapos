<?php

namespace App\Services\Scanner;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Register;
use App\Models\ScanEvent;
use App\Models\ScannerSession;
use App\Models\User;
use App\Models\Presentation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Smartphone scanner déporté de caisse (PRD §8, mode A).
 * Appairage : QR à usage unique (2 min) → le téléphone le lit avec un utilisateur autorisé →
 * le caissier confirme sur le PC → scans transmis, accusés et dédupliqués (SCN-05).
 * Le PC récupère les scans par interrogation courte (réseau local, sans service temps réel).
 */
class ScannerService
{
    public const PAIRING_TTL_SECONDS = 120;

    public const SESSION_TTL_HOURS = 12;

    /** @return array{0: ScannerSession, 1: string} session et jeton en clair (affiché une seule fois dans le QR) */
    public function createPairing(User $user, Register $register): array
    {
        $this->authorize($user, 'sale.create');
        abort_unless($register->pharmacy_id === $user->pharmacy_id, 404);

        // Un seul scanner actif ou en attente par caisse
        ScannerSession::where('register_id', $register->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token = Str::random(48);
        $session = ScannerSession::create([
            'pharmacy_id' => $user->pharmacy_id,
            'register_id' => $register->id,
            'mode' => 'pos',
            'pairing_token_hash' => hash('sha256', $token),
            'pairing_expires_at' => now()->addSeconds(self::PAIRING_TTL_SECONDS),
        ]);

        return [$session, $token];
    }

    /** Le téléphone présente le jeton du QR : la session attend la confirmation du caissier. */
    public function claim(User $user, string $token, ?string $userAgent): ScannerSession
    {
        $this->authorize($user, 'scanner.pair');

        return DB::transaction(function () use ($user, $token, $userAgent) {
            $session = ScannerSession::where('pairing_token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if (! $session || $session->pharmacy_id !== $user->pharmacy_id) {
                throw new ScannerException('invalid_token', "QR d'appairage invalide.");
            }
            if ($session->revoked_at || $session->paired_at || $session->pairing_expires_at->isPast()) {
                throw new ScannerException('token_used', "QR expiré ou déjà utilisé : générez-en un nouveau sur le PC.");
            }

            $device = Device::create([
                'pharmacy_id' => $user->pharmacy_id,
                'user_id' => $user->id,
                'name' => 'Smartphone de '.$user->name,
                'type' => 'smartphone',
                'user_agent' => Str::limit((string) $userAgent, 250, ''),
                'last_seen_at' => now(),
            ]);

            $session->update(['user_id' => $user->id, 'device_id' => $device->id, 'paired_at' => now()]);

            return $session;
        });
    }

    /** Étape 4 de l'appairage : le caissier valide le téléphone affiché sur son écran. */
    public function confirm(User $cashier, ScannerSession $session): ScannerSession
    {
        $this->authorize($cashier, 'sale.create');

        return DB::transaction(function () use ($cashier, $session) {
            $session = ScannerSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->pharmacy_id === $cashier->pharmacy_id, 404);
            if ($session->revoked_at || ! $session->paired_at || $session->confirmed_by) {
                throw new ScannerException('not_pending', "Aucun téléphone en attente de confirmation.");
            }

            $session->update(['confirmed_by' => $cashier->id, 'expires_at' => now()->addHours(self::SESSION_TTL_HOURS)]);
            AuditLog::create([
                'pharmacy_id' => $session->pharmacy_id,
                'user_id' => $cashier->id,
                'action' => 'scanner.paired',
                'auditable_type' => 'scanner_session',
                'auditable_id' => $session->id,
                'new_values' => ['register_id' => $session->register_id, 'phone_user_id' => $session->user_id, 'device_id' => $session->device_id],
            ]);

            return $session;
        });
    }

    public function revoke(User $user, ScannerSession $session): void
    {
        abort_unless($session->pharmacy_id === $user->pharmacy_id, 404);
        if (! $session->revoked_at) {
            $session->update(['revoked_at' => now()]);
            AuditLog::create([
                'pharmacy_id' => $session->pharmacy_id,
                'user_id' => $user->id,
                'action' => 'scanner.revoked',
                'auditable_type' => 'scanner_session',
                'auditable_id' => $session->id,
            ]);
        }
    }

    public static function state(ScannerSession $session): string
    {
        return match (true) {
            (bool) $session->revoked_at => 'revoked',
            $session->confirmed_by && $session->expires_at?->isPast() => 'expired',
            (bool) $session->confirmed_by => 'active',
            (bool) $session->paired_at => 'awaiting_confirmation',
            $session->pairing_expires_at->isPast() => 'expired',
            default => 'waiting_phone',
        };
    }

    /**
     * Réception d'un scan. Idempotent : un même scan_event_id renvoie le même accusé (renvoi réseau).
     *
     * @return array{id:string, sequence:int, status:string, product:?string, duplicate:bool}
     */
    public function ingest(User $user, ScannerSession $session, array $scan): array
    {
        if ($session->user_id !== $user->id || self::state($session) !== 'active') {
            throw new ScannerException('session_inactive', 'Scanner déconnecté : réappairez le téléphone.');
        }

        $id = (string) ($scan['id'] ?? '');
        $sequence = (int) ($scan['sequence'] ?? 0);
        $barcode = trim((string) ($scan['barcode'] ?? ''));
        if (! Str::isUuid($id) || $sequence <= 0 || $barcode === '' || strlen($barcode) > 64) {
            throw new ScannerException('invalid_scan', 'Scan invalide.');
        }

        $existing = ScanEvent::with('presentation.medicine')->find($id);
        if ($existing) {
            if ($existing->scanner_session_id !== $session->id) {
                throw new ScannerException('invalid_scan', 'Scan invalide.');
            }

            return $this->ack($existing, true);
        }

        // Code-barres exact uniquement (pas de recherche textuelle depuis le téléphone)
        $presentation = Presentation::with('medicine')->where('pharmacy_id', $session->pharmacy_id)->where('status', 'active')
            ->where(fn ($q) => $q->where('sku', strtoupper($barcode))
                ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $barcode)->where('is_active', true)))
            ->first();

        try {
            $event = ScanEvent::create([
                'id' => $id,
                'scanner_session_id' => $session->id,
                'sequence' => $sequence,
                'barcode' => $barcode,
                'presentation_id' => $presentation?->id,
                'status' => $presentation ? 'received' : 'unknown_product',
                'scanned_at' => $this->scannedAt($scan['scanned_at'] ?? null),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Même séquence déjà reçue sous un autre identifiant (téléphone réinitialisé) : on la rejette
            $event = ScanEvent::find($id) ?? throw new ScannerException('sequence_conflict', 'Numéro de séquence déjà utilisé.');

            return $this->ack($event->load('presentation.medicine'), true);
        }

        $session->update(['last_sequence' => max($session->last_sequence, $sequence)]);
        Device::whereKey($session->device_id)->update(['last_seen_at' => now()]);

        return $this->ack($event->setRelation('presentation', $presentation), false);
    }

    /**
     * Scans en attente pour la caisse, dans l'ordre de séquence, marqués appliqués de façon atomique
     * (deux onglets ouverts sur la même caisse ne peuvent pas ajouter deux fois le même scan).
     *
     * @return Collection<int, ScanEvent>
     */
    public function pull(ScannerSession $session): Collection
    {
        return DB::transaction(function () use ($session) {
            $events = ScanEvent::where('scanner_session_id', $session->id)
                ->whereIn('status', ['received', 'unknown_product'])
                ->orderBy('sequence')->lockForUpdate()->get();
            if ($events->isEmpty()) {
                return $events;
            }
            ScanEvent::whereIn('id', $events->pluck('id'))->where('status', 'received')->update(['status' => 'applied']);
            ScanEvent::whereIn('id', $events->pluck('id'))->where('status', 'unknown_product')->update(['status' => 'rejected']);

            return $events;
        });
    }

    private function ack(ScanEvent $event, bool $duplicate): array
    {
        $p = $event->presentation;

        return [
            'id' => $event->id,
            'sequence' => $event->sequence,
            'status' => $event->status === 'rejected' ? 'unknown_product' : ($event->status === 'applied' ? 'received' : $event->status),
            'product' => $p ? trim($p->medicine->brand_name.' '.$p->medicine->strength) : null,
            'duplicate' => $duplicate,
        ];
    }

    private function scannedAt($value): Carbon
    {
        try {
            $at = $value ? Carbon::parse($value) : now();
        } catch (\Throwable) {
            $at = now();
        }

        // Horloge du téléphone non fiable : bornée à ±1 jour
        return $at->between(now()->subDay(), now()->addMinute()) ? $at : now();
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw new ScannerException('forbidden', "Vous n'êtes pas autorisé à utiliser le scanner.");
        }
    }
}
