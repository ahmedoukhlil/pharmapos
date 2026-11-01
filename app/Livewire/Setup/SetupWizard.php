<?php

namespace App\Livewire\Setup;

use App\Models\User;
use App\Services\Settings\PharmacySetupService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Assistant de premier démarrage : officine, compte propriétaire, caisses et modes de paiement.
 * Accessible uniquement tant qu'aucune officine n'existe.
 */
#[Layout('components.layouts.app')]
#[Title('Installation — PharmaPOS')]
class SetupWizard extends Component
{
    public const STEPS = [1 => 'Officine', 2 => 'Propriétaire', 3 => 'Caisses et paiements', 4 => 'Vérification'];

    public int $step = 1;

    public array $pharmacy = ['name' => '', 'legal_name' => '', 'nif' => '', 'address' => '', 'city' => 'Nouakchott', 'phone' => ''];

    public array $owner = ['name' => '', 'username' => '', 'phone' => '', 'password' => '', 'password_confirmation' => ''];

    public array $registers = ['Caisse 1'];

    public array $methods = ['CASH' => true, 'BANKILY' => true, 'MASRVI' => true, 'SEDAD' => true, 'CLICK' => false, 'CARD' => false];

    public function mount(): void
    {
        abort_if(PharmacySetupService::installed(), 404);
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

    public function install(PharmacySetupService $setup)
    {
        abort_if(PharmacySetupService::installed(), 404);
        foreach ([1, 2, 3] as $step) {
            $this->step = $step;
            $this->validateStep($step);
        }

        $owner = $setup->install(
            array_map(fn ($v) => trim((string) $v) ?: null, $this->pharmacy),
            array_map('trim', $this->registers),
            array_map('boolval', $this->methods + ['CASH' => true]),
            [
                'name' => trim($this->owner['name']),
                'username' => mb_strtolower(trim($this->owner['username'])),
                'phone' => trim($this->owner['phone']) ?: null,
                'password' => $this->owner['password'],
            ],
        );

        Auth::login($owner);
        session()->regenerate();

        return $this->redirectRoute('catalogue.import');
    }

    private function validateStep(int $step): void
    {
        match ($step) {
            1 => $this->validate([
                'pharmacy.name' => ['required', 'string', 'max:120'],
                'pharmacy.legal_name' => ['nullable', 'string', 'max:160'],
                'pharmacy.nif' => ['nullable', 'string', 'max:30'],
                'pharmacy.address' => ['nullable', 'string', 'max:255'],
                'pharmacy.city' => ['nullable', 'string', 'max:100'],
                'pharmacy.phone' => ['nullable', 'string', 'max:30'],
            ], [], ['pharmacy.name' => "nom de l'officine"]),
            2 => $this->validate([
                'owner.name' => ['required', 'string', 'max:100'],
                'owner.username' => ['required', 'regex:/^[a-zA-Z0-9._-]{3,30}$/', function ($attr, $value, $fail) {
                    if (User::where('username', mb_strtolower(trim($value)))->exists()) {
                        $fail('Cet identifiant est déjà utilisé.');
                    }
                }],
                'owner.phone' => ['nullable', 'string', 'max:30'],
                'owner.password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            ], ['owner.username.regex' => 'Identifiant de 3 à 30 caractères : lettres, chiffres, point, tiret.'],
                ['owner.name' => 'nom', 'owner.username' => 'identifiant', 'owner.password' => 'mot de passe']),
            3 => $this->validate([
                'registers' => ['required', 'array', 'min:1', 'max:20'],
                'registers.*' => ['required', 'string', 'max:50', 'distinct'],
            ], ['registers.*.required' => 'Donnez un nom à chaque caisse.', 'registers.*.distinct' => 'Deux caisses portent le même nom.']),
            default => null,
        };
    }

    public function render()
    {
        return view('livewire.setup.setup-wizard');
    }
}
