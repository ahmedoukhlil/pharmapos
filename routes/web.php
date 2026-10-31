<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\TicketController;
use App\Livewire\Cash\SessionIndex;
use App\Livewire\Cash\SessionShow;
use App\Livewire\Catalogue\CatalogueIndex;
use App\Livewire\Catalogue\ProductEditor;
use App\Livewire\Purchasing\SupplierIndex;
use App\Livewire\Inventory\InventoryIndex;
use App\Livewire\Inventory\InventorySheet;
use App\Livewire\Pos\Checkout;
use App\Livewire\Pos\CloseSession;
use App\Livewire\Pos\Returns;
use App\Livewire\Purchasing\ReceiptEditor;
use App\Livewire\Purchasing\ReceiptIndex;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [LoginController::class, 'show'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/caisse');
    Route::get('/caisse', Checkout::class)->name('pos');
    Route::get('/caisse/cloture', CloseSession::class)->name('pos.close');
    Route::get('/retours', Returns::class)->name('returns');
    Route::get('/avoirs/{saleReturn}', [TicketController::class, 'showReturn'])->name('returns.show');
    Route::get('/receptions', ReceiptIndex::class)->name('receipts.index');
    Route::get('/receptions/nouvelle', ReceiptEditor::class)->name('receipts.create');
    Route::get('/receptions/{receipt}', ReceiptEditor::class)->name('receipts.edit');
    Route::get('/catalogue', CatalogueIndex::class)->name('catalogue.index');
    Route::get('/catalogue/nouveau', ProductEditor::class)->name('catalogue.create');
    Route::get('/catalogue/{medicine}', ProductEditor::class)->name('catalogue.edit');
    Route::get('/fournisseurs', SupplierIndex::class)->name('suppliers.index');
    Route::get('/inventaires', InventoryIndex::class)->name('inventories.index');
    Route::get('/inventaires/{session}', InventorySheet::class)->name('inventories.show');
    Route::get('/sessions', SessionIndex::class)->name('sessions.index');
    Route::get('/sessions/{session}', SessionShow::class)->name('sessions.show');
    Route::get('/tickets/{sale}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');
});
