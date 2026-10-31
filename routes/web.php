<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\TicketController;
use App\Livewire\Cash\SessionIndex;
use App\Livewire\Cash\SessionShow;
use App\Livewire\Pos\Checkout;
use App\Livewire\Pos\CloseSession;
use App\Livewire\Pos\Returns;
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
    Route::get('/sessions', SessionIndex::class)->name('sessions.index');
    Route::get('/sessions/{session}', SessionShow::class)->name('sessions.show');
    Route::get('/tickets/{sale}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');
});
