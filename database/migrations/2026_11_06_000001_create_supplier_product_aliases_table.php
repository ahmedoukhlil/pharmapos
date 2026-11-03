<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lecture des bordereaux : désignation d'un fournisseur → présentation du catalogue,
 * apprise à chaque association confirmée lors d'une réception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_product_aliases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('label_key', 191);
            $table->string('label');
            $table->foreignUlid('presentation_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'supplier_id', 'label_key'], 'supplier_aliases_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_aliases');
    }
};
