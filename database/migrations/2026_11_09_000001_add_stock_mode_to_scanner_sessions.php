<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Scanner smartphone : mode « stock » pour l'écran d'entrée de stock. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE scanner_sessions MODIFY mode ENUM('pos', 'inventory', 'receipt', 'lookup', 'stock') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE scanner_sessions MODIFY mode ENUM('pos', 'inventory', 'receipt', 'lookup') NOT NULL");
        }
    }
};
