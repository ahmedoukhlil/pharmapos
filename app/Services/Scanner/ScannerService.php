<?php

namespace App\Services\Scanner;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Device;
use App\Models\GoodsReceipt;
use App\Models\InventorySession;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryService;
use App\Support\ExpiryDate;
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

    /** Permission requise, sur le PC comme sur le téléphone, selon le mode. */
    public const MODE_PERMISSIONS = ['pos' => 'sale.create', 'inventory' => 'inventory.count', 'receipt' => 'purchase.manage', 'lookup' => 'catalogue.manage', 'stock' => 'stock.adjust'];

    /**
     * QR d'appairage pour une caisse (pos), un inventaire en comptage (inventory) ou une réception en brouillon (receipt).
     *
     * @return array{0: ScannerSession, 1: string} session et jeton en clair (affiché une seule fois dans le QR)
     */
    public function createPairing(User $user, string $mode, string $contextId): array
    {
        $this->authorize($user, self::MODE_PERMISSIONS[$mode] ?? abort(404));
        $this->assertContext($user->pharmacy_id, $mode, $contextId);

        // Le téléphone est associé au compte : un seul scanner par utilisateur, qui suit l'écran ouvert sur le PC
        ScannerSession::where('confirmed_by', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token = Str::random(48);
        $session = ScannerSession::create([
            'pharmacy_id' => $user->pharmacy_id,
            'register_id' => $mode === 'pos' ? $contextId : null,
            'context_id' => $mode === 'pos' ? null : $contextId,
            'mode' => $mode,
            'pairing_token_hash' => hash('sha256', $token),
            'pairing_expires_at' => now()->addSeconds(self::PAIRING_TTL_SECONDS),
        ]);

        return [$session, $token];
    }

    private function assertContext(string $pharmacyId, string $mode, string $contextId): void
    {
        $ok = match ($mode) {
            'pos' => Register::where('pharmacy_id', $pharmacyId)->whereKey($contextId)->exists(),
            'inventory' => InventorySession::where('pharmacy_id', $pharmacyId)->whereKey($contextId)->where('status', 'counting')->exists(),
            'receipt' => GoodsReceipt::where('pharmacy_id', $pharmacyId)->whereKey($contextId)->where('status', 'draft')->exists(),
            // Fiche produit : le téléphone sert seulement à lire un code-barres à associer
            'lookup' => \App\Models\Medicine::where('pharmacy_id', $pharmacyId)->whereKey($contextId)->exists(),
            // Entrée de stock : l'écran n'a pas de document, le contexte est l'officine
            'stock' => $contextId === $pharmacyId,
        };
        if (! $ok) {
            throw new ScannerException('invalid_context', $mode === 'pos' ? 'Caisse invalide.' : 'Document clos : le scanner ne peut plus y être associé.');
        }
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
            $this->authorize($user, self::MODE_PERMISSIONS[$session->mode]);

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
        $this->authorize($cashier, self::MODE_PERMISSIONS[$session->mode]);

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
                'new_values' => ['mode' => $session->mode, 'register_id' => $session->register_id, 'context_id' => $session->context_id, 'phone_user_id' => $session->user_id, 'device_id' => $session->device_id],
            ]);

            return $session;
        });
    }

    /** Écran visé par le téléphone, ex. « receipt:01J… » : le téléphone le compare pour suivre le PC. */
    public static function target(ScannerSession $session): string
    {
        return $session->mode.':'.($session->mode === 'pos' ? $session->register_id : $session->context_id);
    }

    /**
     * Le PC ouvre un autre écran (caisse, réception, inventaire, fiche produit) : le scanner du compte
     * le suit. Refusé si l'utilisateur du PC ou celui du téléphone n'a pas le droit sur cet écran.
     * Les scans encore en attente pour l'écran précédent sont écartés.
     */
    public function attach(User $user, ScannerSession $session, string $mode, string $contextId): bool
    {
        if ($session->confirmed_by !== $user->id || self::state($session) !== 'active' || ! isset(self::MODE_PERMISSIONS[$mode])) {
            return false;
        }
        $permission = self::MODE_PERMISSIONS[$mode];
        if (! $user->hasPermission($permission) || ! $session->user?->hasPermission($permission)) {
            return false;
        }
        if ($session->mode === $mode && ($mode === 'pos' ? $session->register_id : $session->context_id) === $contextId) {
            return true;
        }
        try {
            $this->assertContext($user->pharmacy_id, $mode, $contextId);
        } catch (ScannerException) {
            return false;
        }

        DB::transaction(function () use ($session, $mode, $contextId) {
            ScanEvent::where('scanner_session_id', $session->id)->whereIn('status', ['received', 'unknown_product'])->update(['status' => 'rejected']);
            $session->update([
                'mode' => $mode,
                'register_id' => $mode === 'pos' ? $contextId : null,
                'context_id' => $mode === 'pos' ? null : $contextId,
            ]);
        });

        return true;
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

        if (! in_array($session->mode, ['pos', 'lookup', 'stock'], true) && ! $presentation) {
            throw new ScannerException('unknown_product', "Produit inconnu « $barcode ».");
        }

        try {
            // Le comptage d'inventaire et l'enregistrement du scan sont validés ensemble
            $event = DB::transaction(function () use ($user, $session, $scan, $id, $sequence, $barcode, $presentation) {
                $payload = match ($session->mode) {
                    'receipt' => $this->receiptPayload($session, $scan['payload'] ?? []),
                    'inventory' => $this->applyCount($user, $session, $presentation, $scan['payload'] ?? []),
                    default => null,
                };

                return ScanEvent::create([
                    'id' => $id,
                    'scanner_session_id' => $session->id,
                    'sequence' => $sequence,
                    'barcode' => $barcode,
                    'presentation_id' => $presentation?->id,
                    'payload' => $payload,
                    'status' => match (true) {
                        in_array($session->mode, ['lookup', 'stock'], true) => 'received',
                        ! $presentation => 'unknown_product',
                        $session->mode === 'inventory' => 'applied',
                        default => 'received',
                    },
                    'scanned_at' => $this->scannedAt($scan['scanned_at'] ?? null),
                ]);
            });
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

    /**
     * Ce que le téléphone doit savoir après un scan en mode inventaire ou réception.
     * Inventaire à l'aveugle : jamais la quantité théorique (STK-04).
     */
    public function lookup(User $user, ScannerSession $session, string $barcode): array
    {
        if ($session->user_id !== $user->id || self::state($session) !== 'active' || in_array($session->mode, ['pos', 'lookup', 'stock'], true)) {
            throw new ScannerException('session_inactive', 'Scanner déconnecté : réappairez le téléphone.');
        }

        $barcode = trim($barcode);
        $p = Presentation::with('medicine')->where('pharmacy_id', $session->pharmacy_id)->where('status', 'active')
            ->where(fn ($q) => $q->where('sku', strtoupper($barcode))
                ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $barcode)->where('is_active', true)))
            ->first() ?? throw new ScannerException('unknown_product', "Produit inconnu « $barcode ».");

        $product = [
            'presentation_id' => $p->id,
            'name' => trim($p->medicine->brand_name.' '.$p->medicine->strength),
            'sku' => $p->sku,
            'base_unit' => $p->base_unit,
            'units_per_pack' => $p->units_per_pack,
        ];

        if ($session->mode === 'inventory') {
            $inventory = InventorySession::findOrFail($session->context_id);
            $lines = $inventory->lines()->with(['batch', 'location'])->where('presentation_id', $p->id)->get();
            $other = $inventory->location_id
                ? Batch::where('presentation_id', $p->id)->whereNotIn('id', $lines->pluck('batch_id'))
                    ->whereNotIn('status', ['destroyed', 'returned'])->orderBy('expiry_date')->get()
                : collect();

            return $product + [
                'lines' => $lines->sortBy(fn ($l) => $l->batch->expiry_date)->values()->map(fn ($l) => [
                    'line_id' => $l->id,
                    'lot' => $l->batch->lot_number,
                    'expiry' => $l->batch->expiry_date->format('m/Y'),
                    'location' => $l->location->name,
                    'counted' => $l->recount_quantity ?? $l->counted_quantity,
                ])->all(),
                'other_batches' => $other->map(fn ($b) => ['batch_id' => $b->id, 'lot' => $b->lot_number, 'expiry' => $b->expiry_date->format('m/Y')])->all(),
            ];
        }

        $receipt = GoodsReceipt::findOrFail($session->context_id);

        return $product + [
            'expected' => $receipt->lines()->where('presentation_id', $p->id)->orderBy('line_no')->get()
                ->map(fn ($l) => ['packs' => $l->quantity_packs, 'lot' => $l->lot_number, 'expiry' => $l->expiry_raw])->all(),
        ];
    }

    /** Comptage d'un lot en unités de base : ligne existante ou lot trouvé hors théorique. */
    private function applyCount(User $user, ScannerSession $session, Presentation $presentation, array $payload): array
    {
        $inventory = InventorySession::findOrFail($session->context_id);
        $data = ['quantity' => $payload['quantity'] ?? null, 'device_id' => $session->device_id];
        if (! empty($payload['line_id'])) {
            $data['line_id'] = $payload['line_id'];
        } else {
            $data['batch_id'] = $payload['batch_id'] ?? null;
        }

        try {
            $line = app(InventoryService::class)->count($user, $inventory, $data);
        } catch (InventoryException $e) {
            throw new ScannerException($e->reason, $e->getMessage());
        }
        if ($line->presentation_id !== $presentation->id) {
            throw new ScannerException('wrong_product', 'Ce lot ne correspond pas au produit scanné.');
        }

        return ['line_id' => $line->id, 'quantity' => (int) $data['quantity']];
    }

    /** Lot, péremption et quantité reçue (SCN-08) : contrôlés ici, ajoutés au brouillon par le PC. */
    private function receiptPayload(ScannerSession $session, array $payload): array
    {
        if (! GoodsReceipt::whereKey($session->context_id)->where('status', 'draft')->exists()) {
            throw new ScannerException('session_inactive', 'La réception a été validée ou annulée.');
        }

        $lot = Str::upper(trim((string) ($payload['lot_number'] ?? '')));
        $expiryRaw = trim((string) ($payload['expiry'] ?? ''));
        $packs = $payload['quantity_packs'] ?? null;

        if ($lot === '' || strlen($lot) > 50) {
            throw new ScannerException('lot_required', 'Numéro de lot obligatoire.');
        }
        $expiry = ExpiryDate::parse($expiryRaw, $session->pharmacy_id)
            ?? throw new ScannerException('invalid_expiry', "Péremption illisible « $expiryRaw » (format MM/AAAA).");
        if (! is_numeric($packs) || (int) $packs < 1 || (int) $packs != $packs) {
            throw new ScannerException('invalid_quantity', 'Nombre de boîtes invalide.');
        }

        return ['lot_number' => $lot, 'expiry' => $expiryRaw, 'expiry_date' => $expiry->toDateString(), 'quantity_packs' => (int) $packs];
    }

    private function ack(ScanEvent $event, bool $duplicate): array
    {
        $p = $event->presentation;

        return [
            'id' => $event->id,
            'sequence' => $event->sequence,
            'status' => $event->status === 'rejected' ? 'unknown_product' : ($event->status === 'applied' ? 'received' : $event->status),
            'product' => $p ? trim($p->medicine->brand_name.' '.$p->medicine->strength) : null,
            'payload' => $event->payload,
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
