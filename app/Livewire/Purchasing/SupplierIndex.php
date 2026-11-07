<?php

namespace App\Livewire\Purchasing;

use App\Models\Supplier;
use App\Services\Catalogue\CatalogueService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Fournisseurs - PharmaPOS')]
class SupplierIndex extends Component
{
    /** null = formulaire fermé, '' = nouveau fournisseur, sinon son id. */
    public ?string $editing = null;

    public array $form = [];

    public function edit(?string $id = null): void
    {
        $this->resetErrorBag();
        $s = $id ? $this->supplier($id) : null;
        $this->editing = $id ?? '';
        $this->form = [
            'code' => $s?->code ?? '',
            'name' => $s?->name ?? '',
            'contact_name' => $s?->contact_name ?? '',
            'phone' => $s?->phone ?? '',
            'email' => $s?->email ?? '',
            'address' => $s?->address ?? '',
            'nif' => $s?->nif ?? '',
            'payment_terms_days' => $s?->payment_terms_days ?? 0,
            'is_active' => (bool) ($s?->is_active ?? true),
        ];
    }

    public function save(CatalogueService $service): void
    {
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->form);
        $data['is_active'] = (bool) $this->form['is_active'];
        $service->saveSupplier(auth()->user(), $data, $this->editing ? $this->supplier($this->editing) : null);
        $this->editing = null;
    }

    private function supplier(string $id): Supplier
    {
        return Supplier::where('pharmacy_id', auth()->user()->pharmacy_id)->findOrFail($id);
    }

    public function render()
    {
        abort_unless(auth()->user()->hasPermission('purchase.manage'), 403);

        return view('livewire.purchasing.supplier-index', [
            'suppliers' => Supplier::where('pharmacy_id', auth()->user()->pharmacy_id)->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }
}
