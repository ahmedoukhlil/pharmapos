<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\TicketController;
use App\Livewire\Admin\PharmacyCreate;
use App\Livewire\Admin\PharmacyIndex;
use App\Livewire\Admin\PlatformSettings;
use App\Livewire\Alerts\AlertIndex;
use App\Livewire\Cash\SessionIndex;
use App\Livewire\Cash\SessionShow;
use App\Livewire\Catalogue\CatalogueImport;
use App\Livewire\Catalogue\CatalogueIndex;
use App\Livewire\Catalogue\ProductEditor;
use App\Livewire\Purchasing\SupplierIndex;
use App\Livewire\Inventory\InventoryIndex;
use App\Livewire\Inventory\InventorySheet;
use App\Livewire\Pos\Checkout;
use App\Livewire\Pos\CloseSession;
use App\Livewire\Pos\Returns;
use App\Livewire\Purchasing\InvoiceEditor;
use App\Livewire\Purchasing\InvoiceIndex;
use App\Livewire\Purchasing\OrderEditor;
use App\Livewire\Purchasing\OrderIndex;
use App\Livewire\Purchasing\ReceiptEditor;
use App\Livewire\Purchasing\ReceiptIndex;
use App\Livewire\Reports\ReportIndex;
use App\Http\Controllers\Auth\PasswordController;
use App\Livewire\Settings\PaymentMethodIndex;
use App\Livewire\Settings\StoreLayoutIndex;
use App\Livewire\Settings\UserIndex;
use App\Livewire\Setup\SetupWizard;
use App\Livewire\System\BackupIndex;
use Illuminate\Support\Facades\Route;

Route::get('/installation', SetupWizard::class)->name('setup');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [LoginController::class, 'show'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/caisse');
    Route::get('/admin', PharmacyIndex::class)->name('admin.pharmacies');
    Route::get('/admin/pharmacies/nouvelle', PharmacyCreate::class)->name('admin.pharmacies.create');
    Route::get('/admin/parametres', PlatformSettings::class)->name('admin.settings');
    Route::get('/admin/e-mails', \App\Livewire\Admin\MailSettings::class)->name('admin.mail');
    Route::get('/caisse', Checkout::class)->name('pos');
    Route::get('/caisse/cloture', CloseSession::class)->name('pos.close');
    Route::get('/retours', Returns::class)->name('returns');
    Route::get('/avoirs/{saleReturn}', [TicketController::class, 'showReturn'])->name('returns.show');
    Route::get('/receptions', ReceiptIndex::class)->name('receipts.index');
    Route::get('/receptions/nouvelle', ReceiptEditor::class)->name('receipts.create');
    Route::get('/receptions/{receipt}', ReceiptEditor::class)->name('receipts.edit');
    Route::get('/catalogue', CatalogueIndex::class)->name('catalogue.index');
    Route::get('/catalogue/import', CatalogueImport::class)->name('catalogue.import');
    Route::get('/catalogue/modele.csv', function () {
        $header = implode(';', array_keys(\App\Services\Catalogue\CatalogueImportService::COLUMNS));
        $example = 'medicament;Doliprane;Paracétamol;1000 mg;comprimé;orale;Sanofi;Antalgiques;non;DOLI1000-8;Boîte de 8;comprimé;8;oui;20;120;160;5;3400936000001';

        return response("\u{FEFF}$header\n$example\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modele-catalogue.csv"',
        ]);
    })->name('catalogue.template');
    Route::get('/catalogue/nouveau', ProductEditor::class)->name('catalogue.create');
    Route::get('/catalogue/{medicine}', ProductEditor::class)->name('catalogue.edit');
    Route::get('/rapports', ReportIndex::class)->name('reports.index');
    Route::get('/rapports/export', ReportExportController::class)->name('reports.export');
    Route::get('/rapports/imprimer', [DocumentController::class, 'report'])->name('reports.print');
    Route::get('/alertes', AlertIndex::class)->name('alerts.index');
    Route::get('/sauvegardes', BackupIndex::class)->name('backups.index');
    Route::get('/modes-paiement', PaymentMethodIndex::class)->name('payment-methods.index');
    Route::get('/utilisateurs', UserIndex::class)->name('users.index');
    Route::get('/caisses-emplacements', StoreLayoutIndex::class)->name('store-layout.index');
    Route::get('/mot-de-passe', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/mot-de-passe', [PasswordController::class, 'update'])->name('password.update');
    Route::get('/commandes', OrderIndex::class)->name('orders.index');
    Route::get('/commandes/nouvelle', OrderEditor::class)->name('orders.create');
    Route::get('/commandes/{order}', OrderEditor::class)->name('orders.edit');
    Route::get('/commandes/{order}/bon', [TicketController::class, 'showOrder'])->name('orders.print');
    Route::get('/factures', InvoiceIndex::class)->name('invoices.index');
    Route::get('/factures/nouvelle', InvoiceEditor::class)->name('invoices.create');
    Route::get('/factures/{invoice}', InvoiceEditor::class)->name('invoices.show');
    Route::get('/fournisseurs', SupplierIndex::class)->name('suppliers.index');
    Route::get('/fournisseurs/{supplier}/releve', [DocumentController::class, 'supplierStatement'])->name('suppliers.statement');
    Route::get('/entree-stock', \App\Livewire\Inventory\StockEntry::class)->name('stock-entry');
    Route::get('/corrections-stock', \App\Livewire\Inventory\StockAdjustment::class)->name('stock-adjustments');
    Route::get('/transferts', \App\Livewire\Inventory\StockTransfer::class)->name('transfers');
    Route::get('/inventaires', InventoryIndex::class)->name('inventories.index');
    Route::get('/inventaires/{session}', InventorySheet::class)->name('inventories.show');
    Route::get('/sessions', SessionIndex::class)->name('sessions.index');
    Route::get('/sessions/{session}', SessionShow::class)->name('sessions.show');
    Route::get('/sessions/{session}/imprimer', [DocumentController::class, 'session'])->name('sessions.print');
    Route::get('/tickets/{sale}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('/scanner', [ScannerController::class, 'home'])->name('scanner.home');
    Route::get('/scanner/appairer/{token}', [ScannerController::class, 'pair'])->name('scanner.pair');
    Route::get('/scanner/{session}', [ScannerController::class, 'show'])->name('scanner.show');
    Route::get('/scanner/{session}/etat', [ScannerController::class, 'status'])->name('scanner.status');
    Route::get('/scanner/{session}/produit', [ScannerController::class, 'lookup'])->middleware('throttle:120,1')->name('scanner.lookup');
    Route::get('/scanner/{session}/recherche', [ScannerController::class, 'search'])->middleware('throttle:120,1')->name('scanner.search');
    Route::post('/scanner/{session}/associer', [ScannerController::class, 'link'])->middleware('throttle:60,1')->name('scanner.link');
    Route::post('/scanner/{session}/scans',[ScannerController::class, 'scan'])->middleware('throttle:120,1')->name('scanner.scan');
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');
});
