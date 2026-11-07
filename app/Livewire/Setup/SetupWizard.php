<?php

namespace App\Livewire\Setup;

use App\Services\Admin\TenantService;
use App\Services\Settings\PharmacySetupService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Premier démarrage du SaaS : création du compte super-administrateur, qui crée ensuite
 * les pharmacies clientes depuis la console. Accessible uniquement tant qu'il n'existe pas.
 */
#[Layout('components.layouts.app')]
#[Title('Installation - PharmaPOS')]
class SetupWizard extends Component
{
    public array $admin = ['name' => '', 'username' => '', 'password' => '', 'password_confirmation' => ''];

    public function mount(): void
    {
        abort_if(PharmacySetupService::installed(), 404);
    }

    public function install(TenantService $tenants)
    {
        abort_if(PharmacySetupService::installed(), 404);
        $this->validate([
            'admin.name' => ['required', 'string', 'max:100'],
            'admin.username' => ['required', 'regex:/^[a-zA-Z0-9._-]{3,30}$/'],
            'admin.password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], ['admin.username.regex' => 'Identifiant de 3 à 30 caractères : lettres, chiffres, point, tiret.'],
            ['admin.name' => 'nom', 'admin.username' => 'identifiant', 'admin.password' => 'mot de passe']);

        $user = $tenants->createSuperAdmin($this->admin);
        Auth::login($user);
        session()->regenerate();

        return $this->redirectRoute('admin.pharmacies');
    }

    public function render()
    {
        return view('livewire.setup.setup-wizard');
    }
}
