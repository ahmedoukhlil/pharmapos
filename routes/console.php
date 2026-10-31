<?php

use App\Services\Sales\SaleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('pos:release-expired-reservations', function (SaleService $sales) {
    $this->info($sales->releaseExpiredReservations().' réservation(s) libérée(s).');
})->purpose('Libère le stock réservé par les ventes en attente expirées (POS-15)');

Schedule::command('pos:release-expired-reservations')->everyMinute()->withoutOverlapping();
