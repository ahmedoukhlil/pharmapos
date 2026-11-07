<?php

namespace App\Livewire\Catalogue;

use App\Services\Catalogue\CatalogueImportService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
#[Title('Import catalogue - PharmaPOS')]
class CatalogueImport extends Component
{
    use WithFileUploads;

    public $file;

    public ?array $preview = null;

    public ?array $result = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('catalogue.manage'), 403);
    }

    public function updatedFile(CatalogueImportService $service): void
    {
        $this->preview = $this->result = null;
        $this->validate(['file' => 'required|file|max:10240|extensions:csv,txt,xlsx'], [], ['file' => 'fichier']);
        $this->preview = $service->preview(auth()->user(), $this->rows($service));
    }

    public function import(CatalogueImportService $service): void
    {
        abort_unless($this->file && $this->preview, 422);
        $this->result = $service->import(auth()->user(), $this->rows($service));
        $this->preview = null;
        $this->file = null;
    }

    private function rows(CatalogueImportService $service): array
    {
        try {
            return $service->read($this->file->getRealPath(), $this->file->getClientOriginalExtension());
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'Fichier illisible : utilisez un CSV (séparateur ; ou ,) ou un classeur Excel .xlsx.']);
        }
    }

    public function render()
    {
        return view('livewire.catalogue.catalogue-import', ['columns' => array_keys(CatalogueImportService::COLUMNS)]);
    }
}
