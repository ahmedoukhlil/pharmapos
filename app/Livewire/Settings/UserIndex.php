<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\RequiresReauth;
use App\Models\User;
use App\Services\Settings\UserService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Utilisateurs - PharmaPOS')]
class UserIndex extends Component
{
    use RequiresReauth;

    /** null = formulaire fermé, '' = nouvel utilisateur, sinon son id. */
    public ?string $editing = null;

    public array $form = [];

    #[Url]
    public string $search = '';

    #[Url]
    public bool $showInactive = false;

    /** Mot de passe provisoire à communiquer, affiché une seule fois : [nom, identifiant, mot de passe]. */
    #[Locked]
    public ?array $credentials = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('user.manage'), 403);
    }

    public function edit(?string $id = null): void
    {
        $this->resetErrorBag();
        $this->credentials = null;
        $u = $id ? $this->user($id) : null;
        $this->editing = $id ?? '';
        $this->form = [
            'name' => $u?->name ?? '',
            'username' => $u?->username ?? '',
            'phone' => $u?->phone ?? '',
            'email' => $u?->email ?? '',
            'role' => $u ? app(UserService::class)->currentRole($u)?->slug : 'cashier',
        ];
    }

    public function save(UserService $service): void
    {
        if (! $this->reauthenticated('user.manage', 'save')) {
            return;
        }
        if ($this->editing) {
            $service->update(auth()->user(), $this->user($this->editing), $this->form);
        } else {
            [$user, $password] = $service->create(auth()->user(), $this->form);
            $this->credentials = [$user->name, $user->username, $password];
        }
        $this->editing = null;
    }

    public function toggle(string $id, UserService $service): void
    {
        if (! $this->reauthenticated('user.manage', 'toggle', ['id' => $id])) {
            return;
        }
        $user = $this->user($id);
        $service->setActive(auth()->user(), $user, ! $user->is_active);
    }

    public function resetPassword(string $id, UserService $service): void
    {
        if (! $this->reauthenticated('user.manage', 'resetPassword', ['id' => $id])) {
            return;
        }
        $user = $this->user($id);
        $this->credentials = [$user->name, $user->username, $service->resetPassword(auth()->user(), $user)];
    }

    public function delete(string $id, UserService $service): void
    {
        if (! $this->reauthenticated('user.manage', 'delete', ['id' => $id])) {
            return;
        }
        $service->delete(auth()->user(), $this->user($id));
        if ($this->editing === $id) {
            $this->editing = null;
        }
    }

    private function user(string $id): User
    {
        return User::where('pharmacy_id', auth()->user()->pharmacy_id)->findOrFail($id);
    }

    public function render(UserService $service)
    {
        $users = User::where('pharmacy_id', auth()->user()->pharmacy_id)
            ->with(['roleAssignments.role'])
            ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('username', 'like', "%{$this->search}%")))
            ->orderByDesc('is_active')->orderBy('name')->get();

        return view('livewire.settings.user-index', [
            'users' => $users,
            'roles' => $service->assignableRoles(auth()->user()),
        ]);
    }
}
