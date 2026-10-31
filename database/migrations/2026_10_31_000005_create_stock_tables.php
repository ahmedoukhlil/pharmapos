<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock : journal de mouvements immuable + soldes matérialisés + réservations (PRD §9, POS-13..15).
 * Toutes les quantités sont en unités de base (entiers).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Solde verrouillé par SELECT … FOR UPDATE lors des ventes (POS-13)
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('presentation_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('batch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity')->default(0);
            $table->bigInteger('reserved_quantity')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['batch_id', 'location_id']);
            $table->index(['pharmacy_id', 'presentation_id']);
        });

        // Append-only ; une correction = un mouvement compensatoire
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('presentation_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('batch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->enum('type', [
                'receipt', 'sale', 'sale_return', 'transfer_in', 'transfer_out',
                'adjustment', 'inventory', 'quarantine_in', 'quarantine_out',
                'destruction', 'supplier_return', 'initial',
            ]);
            $table->bigInteger('quantity'); // signé : + entrée, − sortie
            $table->bigInteger('balance_after');
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->string('source_type', 100)->nullable();
            $table->char('source_id', 26)->nullable();
            $table->string('reason')->nullable();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['batch_id', 'occurred_at']);
            $table->index(['pharmacy_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });

        // Réservations des ventes en attente, expirées par tâche planifiée (POS-15)
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('stock_balance_id')->constrained()->restrictOnDelete();
            $table->char('sale_id', 26); // FK ajoutée après création de sales
            $table->bigInteger('quantity');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'released_at']);
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
    }
};
