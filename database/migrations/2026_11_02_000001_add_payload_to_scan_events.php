<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Données saisies sur le téléphone avec le scan : lot, péremption, quantité (modes réception et inventaire).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_events', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('presentation_id');
        });
    }

    public function down(): void
    {
        Schema::table('scan_events', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
