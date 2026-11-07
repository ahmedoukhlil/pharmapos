<?php

namespace App\Livewire\Settings;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Settings\PaymentMethodService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Modes de paiement - PharmaPOS')]
class PaymentMethodIndex extends Component
{
    /** null = formulaire fermé, '' = nouveau mode, sinon son id. */
    public ?string $editing = null;

    public array $form = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('settings.manage'), 403);
    }

    public function edit(?string $id = null): void
    {
        $this->resetErrorBag();
        $m = $id ? $this->method($id) : null;
        $this->editing = $id ?? '';
        $this->form = [
            'name' => $m?->name ?? '',
            'is_cash' => (bool) ($m?->is_cash ?? false),
            'reference_allowed' => (bool) ($m?->reference_allowed ?? true),
        ];
    }

    public function save(PaymentMethodService $service): void
    {
        $service->save(auth()->user(), $this->form, $this->editing ? $this->method($this->editing) : null);
        $this->editing = null;
    }

    public function toggle(string $id, PaymentMethodService $service): void
    {
        $method = $this->method($id);
        $service->setActive(auth()->user(), $method, ! $method->is_active);
    }

    public function delete(string $id, PaymentMethodService $service): void
    {
        $service->delete(auth()->user(), $this->method($id));
        if ($this->editing === $id) {
            $this->editing = null;
        }
    }

    public function move(string $id, int $direction, PaymentMethodService $service): void
    {
        $service->move(auth()->user(), $this->method($id), $direction);
    }

    private function method(string $id): PaymentMethod
    {
        return PaymentMethod::where('pharmacy_id', auth()->user()->pharmacy_id)->findOrFail($id);
    }

    public function render()
    {
        $methods = PaymentMethod::where('pharmacy_id', auth()->user()->pharmacy_id)->orderBy('sort_order')->orderBy('name')->get();

        return view('livewire.settings.payment-method-index', [
            'methods' => $methods,
            'used' => Payment::whereIn('payment_method_id', $methods->pluck('id'))->distinct()->pluck('payment_method_id')->flip(),
        ]);
    }
}
