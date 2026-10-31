<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un brouillon de réception doit conserver lot et péremption saisis avant que le lot
 * ne soit créé à la validation (LOT-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->unsignedSmallInteger('line_no')->default(0)->after('goods_receipt_id');
            $table->string('lot_number', 50)->nullable()->after('batch_id');
            $table->string('expiry_raw', 20)->nullable()->after('lot_number');
            $table->date('expiry_date')->nullable()->after('expiry_raw');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropColumn(['line_no', 'lot_number', 'expiry_raw', 'expiry_date']);
        });
    }
};
