<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryLine;
use App\Models\InventorySession;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DocumentNumberer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventaires complets ou partiels par lot et emplacement (PRD §9, STK-03 à STK-06, QA-10).
 *
 * Cycle : comptage → revue (recomptage possible) → approbation. Le stock théorique est figé à
 * l'ouverture ; à l'approbation, l'ajustement appliqué est compté − théorique figé, si bien que
 * les ventes faites pendant le comptage restent correctement déduites.
 * Toutes les quantités sont en unités de base.
 */
class InventoryService
{
    public function __construct(private readonly DocumentNumberer $numberer) {}

    /**
     * @param  array{scope: string, location_id?: ?string, presentation_ids?: array}  $data
     */
    public function open(User $user, array $data): InventorySession
    {
        $this->authorize($user, 'inventory.count');
        $scope = $data['scope'] ?? 'full';
        if (! in_array($scope, ['full', 'partial', 'cycle'], true)) {
            throw new InventoryException('invalid_scope', "Type d'inventaire invalide.");
        }

        $locationId = $data['location_id'] ?? null;
        if ($locationId && ! Location::where('pharmacy_id', $user->pharmacy_id)->whereKey($locationId)->exists()) {
            throw new InventoryException('invalid_location', 'Emplacement invalide.');
        }
        $presentationIds = array_filter($data['presentation_ids'] ?? []);
        if ($scope !== 'full' && ! $locationId && ! $presentationIds) {
            throw new InventoryException('scope_required', 'Un inventaire partiel doit cibler un emplacement ou des produits.');
        }

        return DB::transaction(function () use ($user, $scope, $locationId, $presentationIds) {
            $session = InventorySession::create([
                'pharmacy_id' => $user->pharmacy_id,
                'location_id' => $locationId,
                'number' => $this->numberer->nextForPharmacy($user->pharmacy_id, 'inventory', 'INV'),
                'scope' => $scope,
                'theoretical_mode' => 'frozen',
                'status' => 'counting',
                'snapshot_at' => now(),
                'created_by' => $user->id,
            ]);

            // Photo du stock théorique, verrouillée pour être cohérente à l'instant de l'ouverture
            $balances = StockBalance::where('pharmacy_id', $user->pharmacy_id)
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->when($presentationIds, fn ($q) => $q->whereIn('presentation_id', $presentationIds))
                ->whereHas('batch', fn ($q) => $q->where('status', '!=', 'destroyed'))
                ->orderBy('batch_id')->orderBy('location_id')
                ->lockForUpdate()
                ->get();

            foreach ($balances as $balance) {
                $session->lines()->create([
                    'presentation_id' => $balance->presentation_id,
                    'batch_id' => $balance->batch_id,
                    'location_id' => $balance->location_id,
                    'theoretical_quantity' => $balance->quantity,
                ]);
            }

            $this->audit($session, $user, 'inventory.opened', ['scope' => $scope, 'lines' => $balances->count()]);

            return $session->load('lines');
        });
    }

    /**
     * Saisie d'un comptage sur une ligne existante, ou ajout d'un lot trouvé hors théorique.
     * En revue avec recomptage demandé, la saisie alimente recount_quantity.
     */
    public function count(User $user, InventorySession $session, array $data): InventoryLine
    {
        $this->authorize($user, 'inventory.count');
        $quantity = $data['quantity'] ?? null;
        if (! is_numeric($quantity) || (int) $quantity < 0 || (int) $quantity != $quantity) {
            throw new InventoryException('invalid_quantity', 'Quantité comptée invalide.');
        }

        return DB::transaction(function () use ($user, $session, $data, $quantity) {
            $session = $this->lockSession($session, $user, ['counting']);

            if (! empty($data['line_id'])) {
                $line = $session->lines()->whereKey($data['line_id'])->lockForUpdate()->first()
                    ?? throw new InventoryException('unknown_line', 'Ligne introuvable.');
            } else {
                $batch = Batch::where('pharmacy_id', $session->pharmacy_id)->find($data['batch_id'] ?? null)
                    ?? throw new InventoryException('unknown_batch', 'Lot inconnu : enregistrez-le par une réception.');
                $location = Location::where('pharmacy_id', $session->pharmacy_id)->find($data['location_id'] ?? $session->location_id)
                    ?? throw new InventoryException('invalid_location', 'Emplacement invalide.');
                if ($session->location_id && $location->id !== $session->location_id) {
                    throw new InventoryException('out_of_scope', "Cet emplacement n'est pas couvert par l'inventaire.");
                }

                $line = $session->lines()->firstOrCreate(
                    ['batch_id' => $batch->id, 'location_id' => $location->id],
                    ['presentation_id' => $batch->presentation_id, 'theoretical_quantity' => 0],
                );
            }

            $field = $line->counted_quantity !== null && $session->approvals()->where('decision', 'recount')->exists()
                ? 'recount_quantity'
                : 'counted_quantity';

            $line->update([
                $field => (int) $quantity,
                'counted_by' => $user->id,
                'device_id' => $data['device_id'] ?? null,
                'counted_at' => now(),
            ]);

            return $line;
        });
    }

    /**
     * Fin du comptage : calcule les écarts. Les lignes non comptées sont considérées à zéro
     * seulement si on le confirme explicitement.
     */
    public function submit(User $user, InventorySession $session, bool $uncountedAsZero = false): InventorySession
    {
        $this->authorize($user, 'inventory.count');

        return DB::transaction(function () use ($user, $session, $uncountedAsZero) {
            $session = $this->lockSession($session, $user, ['counting']);

            $uncounted = $session->lines()->whereNull('counted_quantity')->count();
            if ($uncounted > 0 && ! $uncountedAsZero) {
                throw new InventoryException('uncounted_lines', "$uncounted ligne(s) non comptée(s).");
            }
            $session->lines()->whereNull('counted_quantity')->update(['counted_quantity' => 0]);

            foreach ($session->lines()->with('batch')->get() as $line) {
                $variance = ($line->recount_quantity ?? $line->counted_quantity) - $line->theoretical_quantity;
                $line->update([
                    'variance' => $variance,
                    'variance_value' => round($variance * (float) $line->batch->unit_cost, 2),
                ]);
            }

            $session->update(['status' => 'review']);

            return $session;
        });
    }

    /** Revue : renvoie en comptage (recomptage des lignes en écart) avec un commentaire. */
    public function requestRecount(User $user, InventorySession $session, ?string $comment = null): InventorySession
    {
        $this->authorize($user, 'inventory.approve');

        return DB::transaction(function () use ($user, $session, $comment) {
            $session = $this->lockSession($session, $user, ['review']);
            $session->approvals()->create(['approved_by' => $user->id, 'decision' => 'recount', 'comment' => $comment]);
            $session->update(['status' => 'counting']);

            return $session;
        });
    }

    /**
     * Approbation (STK-06) : un mouvement « inventory » par ligne en écart, appliqué au solde courant.
     */
    public function approve(User $user, InventorySession $session, ?string $comment = null): InventorySession
    {
        $this->authorize($user, 'inventory.approve');

        return DB::transaction(function () use ($user, $session, $comment) {
            $session = $this->lockSession($session, $user, ['review']);
            $lines = $session->lines()->with('batch')->where('variance', '!=', 0)
                ->orderBy('batch_id')->orderBy('location_id')->get();

            foreach ($lines as $line) {
                $balance = StockBalance::where('batch_id', $line->batch_id)->where('location_id', $line->location_id)->lockForUpdate()->first()
                    ?? StockBalance::create([
                        'pharmacy_id' => $session->pharmacy_id,
                        'presentation_id' => $line->presentation_id,
                        'batch_id' => $line->batch_id,
                        'location_id' => $line->location_id,
                        'quantity' => 0,
                    ]);

                $balance->quantity += $line->variance;
                if ($balance->quantity < 0) {
                    throw new InventoryException('negative_stock',
                        "L'ajustement du lot {$line->batch->lot_number} rendrait le stock négatif : recomptez.");
                }
                $balance->version++;
                $balance->save();

                StockMovement::create([
                    'pharmacy_id' => $session->pharmacy_id,
                    'presentation_id' => $line->presentation_id,
                    'batch_id' => $line->batch_id,
                    'location_id' => $line->location_id,
                    'type' => 'inventory',
                    'quantity' => $line->variance,
                    'balance_after' => $balance->quantity,
                    'unit_cost' => $line->batch->unit_cost,
                    'source_type' => 'inventory_session',
                    'source_id' => $session->id,
                    'reason' => "Inventaire {$session->number}",
                    'user_id' => $user->id,
                    'occurred_at' => now(),
                ]);
            }

            $session->approvals()->create(['approved_by' => $user->id, 'decision' => 'approved', 'comment' => $comment]);
            $session->update(['status' => 'approved']);

            $this->audit($session, $user, 'inventory.approved', [
                'adjusted_lines' => $lines->count(),
                'variance_value' => (string) $lines->sum('variance_value'),
            ]);

            OutboxEvent::create([
                'event_id' => (string) Str::uuid(),
                'pharmacy_id' => $session->pharmacy_id,
                'aggregate_type' => 'inventory_session',
                'aggregate_id' => $session->id,
                'event_type' => 'inventory.approved',
                'sequence' => 1,
                'payload' => ['number' => $session->number, 'adjusted_lines' => $lines->count()],
                'occurred_at' => now(),
            ]);

            return $session;
        });
    }

    public function cancel(User $user, InventorySession $session): InventorySession
    {
        $this->authorize($user, 'inventory.approve');

        return DB::transaction(function () use ($user, $session) {
            $session = $this->lockSession($session, $user, ['counting', 'review']);
            $session->update(['status' => 'cancelled']);
            $this->audit($session, $user, 'inventory.cancelled', []);

            return $session;
        });
    }

    private function lockSession(InventorySession $session, User $user, array $statuses): InventorySession
    {
        $session = InventorySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
        if ($session->pharmacy_id !== $user->pharmacy_id) {
            throw new InventoryException('forbidden', 'Inventaire introuvable.');
        }
        if (! in_array($session->status, $statuses, true)) {
            throw new InventoryException('invalid_status', "Opération impossible : l'inventaire est {$session->status}.");
        }

        return $session;
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw new InventoryException('forbidden', "Vous n'êtes pas autorisé à effectuer cette opération d'inventaire.");
        }
    }

    private function audit(InventorySession $session, User $user, string $action, array $values): void
    {
        AuditLog::create([
            'pharmacy_id' => $session->pharmacy_id,
            'user_id' => $user->id,
            'action' => $action,
            'auditable_type' => 'inventory_session',
            'auditable_id' => $session->id,
            'new_values' => ['number' => $session->number] + $values,
        ]);
    }
}
