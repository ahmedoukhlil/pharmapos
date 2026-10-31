<?php

namespace App\Services\Cash;

use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\OutboxEvent;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sessions de caisse (PRD §11, POS-10, QA-14) : mouvements, clôture avec écart, validation.
 * Une session validée est verrouillée ; toute correction ultérieure est une nouvelle écriture.
 * Montants calculés en centimes entiers.
 */
class CashSessionService
{
    /**
     * Récapitulatif calculé à partir des écritures (paiements, rendus, mouvements).
     *
     * @return array{opening: int, by_method: array, cash_sales: int, change: int, cash_in: int, cash_out: int,
     *               expected_cash: int, sales_count: int, sales_total: int, refunds: int}
     */
    public function summary(CashSession $session): array
    {
        $methods = PaymentMethod::where('pharmacy_id', $session->pharmacy_id)->get()->keyBy('id');

        $payments = $session->payments()
            ->selectRaw('payment_method_id, kind, SUM(amount) as total')
            ->groupBy('payment_method_id', 'kind')
            ->get();

        $sales = $session->sales()->where('status', 'finalized')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as total, COALESCE(SUM(change_given), 0) as change_given')
            ->first();
        $change = $this->cents($sales->change_given);

        $byMethod = [];
        $cashSales = 0;
        $refunds = 0;
        foreach ($payments as $row) {
            $method = $methods[$row->payment_method_id];
            $amount = $this->cents($row->total);
            $byMethod[$method->code] ??= ['name' => $method->name, 'is_cash' => $method->is_cash, 'amount' => 0];
            $byMethod[$method->code]['amount'] += $amount;
            if ($row->kind === 'refund') {
                $refunds += -$amount;
            }
            if ($method->is_cash) {
                $cashSales += $amount;
            }
        }
        // Le rendu de monnaie sort des espèces
        foreach ($byMethod as &$m) {
            if ($m['is_cash']) {
                $m['amount'] -= $change;
                break;
            }
        }
        unset($m);

        $movements = $session->movements()->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');
        $cashIn = $this->cents($movements['cash_in'] ?? 0);
        $cashOut = $this->cents($movements['cash_out'] ?? 0);
        $corrections = $this->cents($movements['correction'] ?? 0);
        $opening = $this->cents($session->opening_float);

        return [
            'opening' => $opening,
            'by_method' => $byMethod,
            'cash_sales' => $cashSales,
            'change' => $change,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'corrections' => $corrections,
            'expected_cash' => $opening + $cashSales - $change + $cashIn - $cashOut + $corrections,
            'sales_count' => (int) $sales->n,
            'sales_total' => $this->cents($sales->total),
            'refunds' => $refunds,
        ];
    }

    /** Entrée ou sortie d'espèces hors vente (approvisionnement, dépense, dépôt en banque). */
    public function recordMovement(CashSession $session, User $user, string $type, string $amount, string $reason): CashMovement
    {
        if (! in_array($type, ['cash_in', 'cash_out'], true)) {
            throw new CashSessionException('invalid_type', 'Type de mouvement invalide.');
        }
        if (! $user->hasPermission('cash.movement')) {
            throw new CashSessionException('forbidden', "Vous n'êtes pas autorisé à enregistrer un mouvement de caisse.");
        }
        $cents = $this->cents($amount);
        if ($cents <= 0) {
            throw new CashSessionException('invalid_amount', 'Le montant doit être positif.');
        }
        if (trim($reason) === '') {
            throw new CashSessionException('reason_required', 'Le motif est obligatoire.');
        }

        return DB::transaction(function () use ($session, $user, $type, $cents, $reason) {
            $session = $this->lockOpen($session);

            if ($type === 'cash_out' && $cents > $this->summary($session)['expected_cash']) {
                throw new CashSessionException('insufficient_cash', 'Sortie supérieure aux espèces théoriques en caisse.');
            }

            $movement = $session->movements()->create([
                'type' => $type,
                'amount' => $this->money($cents),
                'reason' => trim($reason),
                'user_id' => $user->id,
            ]);

            $this->audit($session, $user, "cash.$type", ['amount' => $movement->amount, 'reason' => $movement->reason]);

            return $movement;
        });
    }

    /**
     * Clôture : fige le récapitulatif, le montant compté et l'écart. Aucune vente n'est plus possible.
     */
    public function close(CashSession $session, User $user, string $countedCash, ?string $notes = null): CashSession
    {
        if (! $user->hasPermission('cash.open_close')) {
            throw new CashSessionException('forbidden', "Vous n'êtes pas autorisé à clôturer une caisse.");
        }
        if (! is_numeric($countedCash) || (float) $countedCash < 0) {
            throw new CashSessionException('invalid_amount', 'Montant compté invalide.');
        }

        return DB::transaction(function () use ($session, $user, $countedCash, $notes) {
            $session = $this->lockOpen($session);

            $held = $session->sales()->where('status', 'on_hold')->count();
            if ($held > 0) {
                throw new CashSessionException('held_sales', "$held vente(s) en attente à reprendre ou annuler avant la clôture.");
            }

            $summary = $this->summary($session);
            $counted = $this->cents($countedCash);
            $difference = $counted - $summary['expected_cash'];

            $session->update([
                'status' => 'closed',
                'closed_by' => $user->id,
                'closed_at' => now(),
                'expected_cash' => $this->money($summary['expected_cash']),
                'counted_cash' => $this->money($counted),
                'difference' => $this->money($difference),
                'totals_by_method' => array_map(fn ($m) => ['name' => $m['name'], 'amount' => $this->money($m['amount'])], $summary['by_method']),
                'notes' => $notes,
            ]);

            $this->audit($session, $user, 'cash.closed', [
                'expected_cash' => $session->expected_cash,
                'counted_cash' => $session->counted_cash,
                'difference' => $session->difference,
            ]);
            $this->outbox($session, 'cash_session.closed', 1);

            return $session;
        });
    }

    /**
     * Validation par un responsable : la session devient définitivement verrouillée.
     */
    public function validate(CashSession $session, User $validator, ?string $comment = null): CashSession
    {
        if (! $validator->hasPermission('cash.validate') || $validator->pharmacy_id !== $session->pharmacy_id) {
            throw new CashSessionException('forbidden', "Vous n'êtes pas autorisé à valider une clôture.");
        }

        return DB::transaction(function () use ($session, $validator, $comment) {
            $session = CashSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== 'closed') {
                throw new CashSessionException('not_closed', 'Seule une session clôturée peut être validée.');
            }
            // Séparation des tâches : un écart doit être validé par une autre personne que celle qui a clôturé
            if ((float) $session->difference != 0 && $session->closed_by === $validator->id) {
                throw new CashSessionException('self_validation', 'Un écart doit être validé par une autre personne.');
            }

            $session->update([
                'status' => 'validated',
                'validated_by' => $validator->id,
                'validated_at' => now(),
            ]);

            $this->audit($session, $validator, 'cash.validated', ['difference' => $session->difference, 'comment' => $comment]);
            $this->outbox($session, 'cash_session.validated', 2);

            return $session;
        });
    }

    private function lockOpen(CashSession $session): CashSession
    {
        $session = CashSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
        if ($session->status !== 'open') {
            throw new CashSessionException('session_not_open', 'La session de caisse est déjà clôturée.');
        }

        return $session;
    }

    private function audit(CashSession $session, User $user, string $action, array $values): void
    {
        AuditLog::create([
            'pharmacy_id' => $session->pharmacy_id,
            'user_id' => $user->id,
            'action' => $action,
            'auditable_type' => 'cash_session',
            'auditable_id' => $session->id,
            'new_values' => $values,
        ]);
    }

    private function outbox(CashSession $session, string $type, int $sequence): void
    {
        OutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'pharmacy_id' => $session->pharmacy_id,
            'aggregate_type' => 'cash_session',
            'aggregate_id' => $session->id,
            'event_type' => $type,
            'sequence' => $sequence,
            'payload' => $session->only(['status', 'expected_cash', 'counted_cash', 'difference', 'totals_by_method']),
            'occurred_at' => now(),
        ]);
    }

    private function cents(int|float|string|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
