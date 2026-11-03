<?php

namespace App\Livewire\Admin;

use App\Models\Pharmacy;
use App\Services\Admin\TenantService;
use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Console SaaS : pharmacies clientes, activité, abonnement, suspension et dépannage du propriétaire.
 */
#[Layout('components.layouts.app')]
#[Title('Pharmacies — PharmaPOS Admin')]
class PharmacyIndex extends Component
{
    public string $search = '';

    public string $filter = '';

    /** Pharmacie dont on édite l'abonnement ou que l'on suspend */
    public ?string $editing = null;

    public string $mode = '';

    public array $form = ['plan' => '', 'subscription_ends_at' => '', 'reason' => ''];

    /** [pharmacie, code, identifiant, mot de passe provisoire] après réinitialisation */
    public ?array $credentials = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
    }

    public function edit(string $id, string $mode): void
    {
        $p = Pharmacy::findOrFail($id);
        $this->editing = $p->id;
        $this->mode = in_array($mode, ['suspend', 'users'], true) ? $mode : 'subscription';
        $this->form = ['plan' => (string) $p->plan, 'subscription_ends_at' => (string) $p->subscription_ends_at?->toDateString(), 'reason' => ''];
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(TenantService $tenants): void
    {
        $p = Pharmacy::findOrFail($this->editing);
        if ($this->mode === 'suspend') {
            $tenants->suspend(auth()->user(), $p, $this->form['reason']);
        } else {
            $this->validate(['form.plan' => ['nullable', 'string', 'max:30'], 'form.subscription_ends_at' => ['nullable', 'date']],
                [], ['form.subscription_ends_at' => "date d'échéance"]);
            $tenants->setSubscription(auth()->user(), $p, $this->form['plan'], $this->form['subscription_ends_at']);
        }
        $this->editing = null;
    }

    public function extend(string $id, int $months, TenantService $tenants): void
    {
        $tenants->extend(auth()->user(), Pharmacy::findOrFail($id), in_array($months, [1, 3, 6, 12], true) ? $months : 1);
    }

    public function reactivate(string $id, TenantService $tenants): void
    {
        $tenants->reactivate(auth()->user(), Pharmacy::findOrFail($id));
    }

    public function resetOwner(string $id, TenantService $tenants): void
    {
        $p = Pharmacy::findOrFail($id);
        [$owner, $password] = $tenants->resetOwnerPassword(auth()->user(), $p);
        $this->credentials = [$p->name, $p->code, $owner->username, $password];
    }

    public function resetUser(string $userId, TenantService $tenants): void
    {
        $user = \App\Models\User::with('pharmacy')->whereNotNull('pharmacy_id')->findOrFail($userId);
        $password = $tenants->resetUserPassword(auth()->user(), $user);
        $this->credentials = [$user->pharmacy->name, $user->pharmacy->code, $user->username, $password];
    }

    /** Utilisateurs de la pharmacie ouverte en mode « Utilisateurs ». */
    #[Computed]
    public function editingUsers()
    {
        return $this->mode === 'users' && $this->editing
            ? \App\Models\User::where('pharmacy_id', $this->editing)->with('roles')->orderByDesc('is_active')->orderBy('name')->get()
            : collect();
    }

    #[Computed]
    public function pharmacies()
    {
        $since = now()->subDays(30);

        return Pharmacy::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")->orWhere('city', 'like', "%{$this->search}%")))
            ->when($this->filter === 'suspended', fn ($q) => $q->where('is_active', false))
            ->when($this->filter === 'expired', fn ($q) => $q->where('is_active', true)->whereDate('subscription_ends_at', '<', today()))
            ->when($this->filter === 'expiring', fn ($q) => $q->where('is_active', true)->whereDate('subscription_ends_at', '>=', today())
                ->whereDate('subscription_ends_at', '<=', today()->addDays(15)))
            ->withCount(['users' => fn ($q) => $q->where('is_active', true)])
            ->addSelect([
                'last_sale_at' => DB::table('sales')->selectRaw('MAX(finalized_at)')->whereColumn('sales.pharmacy_id', 'pharmacies.id')->where('status', 'finalized'),
                'sales_30d' => DB::table('sales')->selectRaw('COUNT(*)')->whereColumn('sales.pharmacy_id', 'pharmacies.id')->where('status', 'finalized')->where('finalized_at', '>=', $since),
                'revenue_30d' => DB::table('sales')->selectRaw('COALESCE(SUM(total), 0)')->whereColumn('sales.pharmacy_id', 'pharmacies.id')->where('status', 'finalized')->where('finalized_at', '>=', $since),
            ])
            ->orderBy('name')->get();
    }

    public function render()
    {
        $all = Pharmacy::all();

        return view('livewire.admin.pharmacy-index', [
            'counts' => [
                'total' => $all->count(),
                'active' => $all->filter->isAccessible()->count(),
                'suspended' => $all->where('is_active', false)->count(),
                'expired' => $all->filter(fn ($p) => $p->is_active && $p->subscriptionExpired())->count(),
            ],
            'backupIssue' => app(BackupService::class)->issue(),
        ]);
    }
}
