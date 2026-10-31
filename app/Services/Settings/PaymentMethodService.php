<?php

namespace App\Services\Settings;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Modes de paiement de l'officine : ajout, modification, ordre, activation et suppression.
 * Un mode déjà utilisé ne peut être que désactivé (historique des ventes et des clôtures), et son
 * type espèces/électronique est figé ; au moins un mode espèces actif est toujours conservé.
 */
class PaymentMethodService
{
    public function save(User $user, array $data, ?PaymentMethod $method = null): PaymentMethod
    {
        $this->authorize($user, $method);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['name' => 'Le libellé est obligatoire (60 caractères maximum).']);
        }
        $duplicate = PaymentMethod::where('pharmacy_id', $user->pharmacy_id)->where('name', $name)
            ->when($method, fn ($q) => $q->whereKeyNot($method->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'Un mode de paiement porte déjà ce nom.']);
        }

        $isCash = (bool) ($data['is_cash'] ?? false);
        $values = [
            'name' => $name,
            'is_cash' => $isCash,
            // Une référence (n° de transaction) n'a de sens que pour un paiement électronique
            'reference_allowed' => ! $isCash && (bool) ($data['reference_allowed'] ?? true),
        ];

        return DB::transaction(function () use ($user, $method, $values) {
            if ($method) {
                if ($values['is_cash'] !== $method->is_cash && $this->used($method)) {
                    throw ValidationException::withMessages(['is_cash' => 'Ce mode a déjà servi à des paiements : son type ne peut plus changer.']);
                }
                $old = $method->only(array_keys($values));
                $method->update($values);
                $this->ensureCash($user->pharmacy_id);
                $this->audit($user, 'payment_method.updated', $method, $values, $old);

                return $method;
            }

            $method = PaymentMethod::create($values + [
                'pharmacy_id' => $user->pharmacy_id,
                'code' => $this->code($user->pharmacy_id, $values['name']),
                'is_active' => true,
                'sort_order' => (int) PaymentMethod::where('pharmacy_id', $user->pharmacy_id)->max('sort_order') + 1,
            ]);
            $this->audit($user, 'payment_method.created', $method, $values);

            return $method;
        });
    }

    public function setActive(User $user, PaymentMethod $method, bool $active): void
    {
        $this->authorize($user, $method);
        DB::transaction(function () use ($user, $method, $active) {
            $method->update(['is_active' => $active]);
            $this->ensureCash($user->pharmacy_id);
            $this->audit($user, $active ? 'payment_method.activated' : 'payment_method.deactivated', $method, ['is_active' => $active]);
        });
    }

    /** Supprime un mode jamais utilisé ; sinon il faut le désactiver. */
    public function delete(User $user, PaymentMethod $method): void
    {
        $this->authorize($user, $method);
        if ($this->used($method)) {
            throw ValidationException::withMessages(['method' => "« {$method->name} » a déjà servi à des paiements : désactivez-le au lieu de le supprimer."]);
        }
        DB::transaction(function () use ($user, $method) {
            $this->audit($user, 'payment_method.deleted', $method, [], $method->only(['code', 'name']));
            $method->delete();
            $this->ensureCash($user->pharmacy_id);
        });
    }

    /** Monte (-1) ou descend (+1) un mode dans l'ordre d'affichage en caisse. */
    public function move(User $user, PaymentMethod $method, int $direction): void
    {
        $this->authorize($user, $method);
        $ordered = PaymentMethod::where('pharmacy_id', $user->pharmacy_id)->orderBy('sort_order')->orderBy('name')->get()->values();
        $index = $ordered->search(fn ($m) => $m->id === $method->id);
        $target = $index + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= $ordered->count()) {
            return;
        }
        $ids = $ordered->pluck('id')->all();
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $id) {
                PaymentMethod::whereKey($id)->update(['sort_order' => $i]);
            }
        });
    }

    public function used(PaymentMethod $method): bool
    {
        return Payment::where('payment_method_id', $method->id)->exists();
    }

    private function ensureCash(string $pharmacyId): void
    {
        if (! PaymentMethod::where('pharmacy_id', $pharmacyId)->where('is_active', true)->where('is_cash', true)->exists()) {
            throw ValidationException::withMessages(['method' => 'Au moins un mode espèces doit rester actif (rendu monnaie et fond de caisse).']);
        }
    }

    private function code(string $pharmacyId, string $name): string
    {
        $base = Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->limit(24, '')->value() ?: 'MODE';
        $code = $base;
        for ($i = 2; PaymentMethod::where('pharmacy_id', $pharmacyId)->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }

    private function authorize(User $user, ?PaymentMethod $method): void
    {
        if (! $user->hasPermission('settings.manage') || ($method && $method->pharmacy_id !== $user->pharmacy_id)) {
            abort(403);
        }
    }

    private function audit(User $user, string $action, PaymentMethod $method, array $new, array $old = []): void
    {
        AuditLog::create(['pharmacy_id' => $user->pharmacy_id, 'user_id' => $user->id, 'action' => $action,
            'auditable_type' => 'payment_method', 'auditable_id' => $method->id,
            'old_values' => $old ?: null, 'new_values' => $new ?: null]);
    }
}
