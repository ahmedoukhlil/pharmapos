<?php

namespace App\Livewire\Concerns;

use App\Models\Permission;
use App\Support\Reauth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Suspend une action sensible le temps de redemander le mot de passe, puis la rejoue.
 * Usage en tête d'action : if (! $this->reauthenticated('sale.refund', 'submit')) { return; }
 * La vue inclut <x-reauth-modal :pending="$reauth" />.
 */
trait RequiresReauth
{
    /** Action en attente : permission, libellé, méthode, arguments. Verrouillée côté serveur. */
    #[Locked]
    public ?array $reauth = null;

    public string $reauthPassword = '';

    protected function reauthenticated(string $permission, string $method, array $args = []): bool
    {
        if (! Reauth::requires($permission) || Reauth::isFresh(auth()->user())) {
            return true;
        }

        $this->reauth = [
            'permission' => $permission,
            'label' => Permission::where('slug', $permission)->value('name') ?? $permission,
            'method' => $method,
            'args' => $args,
        ];
        $this->reauthPassword = '';
        $this->resetErrorBag('reauthPassword');

        return false;
    }

    public function confirmReauth()
    {
        if (! $this->reauth) {
            return null;
        }

        try {
            Reauth::confirm(auth()->user(), $this->reauthPassword, $this->reauth['permission']);
        } catch (ValidationException $e) {
            $this->reauthPassword = '';
            throw $e;
        }

        $pending = $this->reauth;
        $this->reauth = null;
        $this->reauthPassword = '';
        $this->resetErrorBag('reauthPassword');

        return app()->call([$this, $pending['method']], $pending['args']);
    }

    public function cancelReauth(): void
    {
        $this->reauth = null;
        $this->reauthPassword = '';
    }
}
