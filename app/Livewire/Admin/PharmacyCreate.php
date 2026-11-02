<?php

namespace App\Livewire\Admin;

use App\Models\Pharmacy;
use App\Services\Admin\TenantService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Console SaaS : nouvelle pharmacie cliente en quatre étapes (officine et abonnement,
 * propriétaire, caisses et paiements, vérification). Le propriétaire reçoit un mot de passe
 * provisoire, affiché une seule fois, qu'il devra changer à sa première connexion.
 */
#[Layout('components.layouts.app')]
#[Title('Nouvelle pharmacie — PharmaPOS Admin')]
class PharmacyCreate extends Component
{
    public const STEPS = [1 => 'Officine', 2 => 'Propriétaire', 3 => 'Caisses et paiements', 4 => 'Vérification'];

    public int $step = 1;

    public array $pharmacy = ['name' => '', 'code' => '', 'legal_name' => '', 'nif' => '', 'address' => '', 'city' => 'Nouakchott', 'phone' => '',
        'plan' => '', 'subscription_ends_at' => ''];

    public array $owner = ['name' => '', 'username' => '', 'phone' => ''];

    public array $registers = ['Caisse 1'];

    public array $methods = ['CASH' => true, 'BANKILY' => true, 'MASRVI' => true, 'SEDAD' => true, 'CLICK' => false, 'CARD' => false];

    /** [code pharmacie, nom de la pharmacie, identifiant, mot de passe provisoire] après création */
    public ?array $credentials = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        $this->pharmacy['subscription_ends_at'] = today()->addMonth()->toDateString();
    }

    public function next(): void
    {
        $this->validateStep($this->step);
        $this->step = min(4, $this->step + 1);
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function addRegister(): void
    {
        if (count($this->registers) < 20) {
            $this->registers[] = 'Caisse '.(count($this->registers) + 1);
        }
    }

    public function removeRegister(int $i): void
    {
        if (count($this->registers) > 1) {
            unset($this->registers[$i]);
            $this->registers = array_values($this->registers);
        }
    }

    public function create(TenantService $tenants): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        foreach ([1, 2, 3] as $step) {
            $this->step = $step;
            $this->validateStep($step);
        }

        [$pharmacy, $owner, $password] = $tenants->create(
            auth()->user(),
            array_map(fn ($v) => trim((string) $v) ?: null, $this->pharmacy),
            array_map('trim', $this->registers),
            array_map('boolval', $this->methods + ['CASH' => true]),
            $this->owner,
        );
        $this->credentials = [$pharmacy->code, $pharmacy->name, $owner->username, $password];
    }

    private function validateStep(int $step): void
    {
        match ($step) {
            1 => $this->validate([
                'pharmacy.name' => ['required', 'string', 'max:120'],
                'pharmacy.code' => ['nullable', 'regex:/^[a-zA-Z0-9]{3,20}$/', function ($attr, $value, $fail) {
                    if (filled($value) && Pharmacy::where('code', strtoupper(trim($value)))->exists()) {
                        $fail('Ce code est déjà utilisé par une autre pharmacie.');
                    }
                }],
                'pharmacy.legal_name' => ['nullable', 'string', 'max:160'],
                'pharmacy.nif' => ['nullable', 'string', 'max:30'],
                'pharmacy.address' => ['nullable', 'string', 'max:255'],
                'pharmacy.city' => ['nullable', 'string', 'max:100'],
                'pharmacy.phone' => ['nullable', 'string', 'max:30'],
                'pharmacy.plan' => ['nullable', 'string', 'max:30'],
                'pharmacy.subscription_ends_at' => ['nullable', 'date', 'after_or_equal:today'],
            ], ['pharmacy.code.regex' => 'Code de 3 à 20 lettres ou chiffres, sans espace.'],
                ['pharmacy.name' => "nom de l'officine", 'pharmacy.subscription_ends_at' => "date d'échéance"]),
            2 => $this->validate([
                'owner.name' => ['required', 'string', 'max:100'],
                'owner.username' => ['required', 'regex:/^[a-zA-Z0-9._-]{3,30}$/'],
                'owner.phone' => ['nullable', 'string', 'max:30'],
            ], ['owner.username.regex' => 'Identifiant de 3 à 30 caractères : lettres, chiffres, point, tiret.'],
                ['owner.name' => 'nom', 'owner.username' => 'identifiant']),
            3 => $this->validate([
                'registers' => ['required', 'array', 'min:1', 'max:20'],
                'registers.*' => ['required', 'string', 'max:50', 'distinct'],
            ], ['registers.*.required' => 'Donnez un nom à chaque caisse.', 'registers.*.distinct' => 'Deux caisses portent le même nom.']),
            default => null,
        };
    }

    public function render()
    {
        return view('livewire.admin.pharmacy-create');
    }
}
