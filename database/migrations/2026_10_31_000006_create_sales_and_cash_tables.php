<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caisse, sessions, ventes, paiements déclaratifs et retours (PRD §7, §11, POS-16).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Espèces, Bankily, Masrvi… : déclaratifs, aucune confirmation automatique
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_cash')->default(false);
            $table->boolean('reference_allowed')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['pharmacy_id', 'code']);
        });

        // Séquences sans trou (tickets, avoirs, réceptions…), incrémentées sous verrou
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('register_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('document_type', 30);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['pharmacy_id', 'register_id', 'document_type', 'fiscal_year'], 'doc_seq_unique');
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('register_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 14, 2);
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->nullable();
            $table->json('totals_by_method')->nullable();
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable(); // verrouillée une fois validée
            $table->enum('status', ['open', 'closed', 'validated'])->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['register_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('cash_session_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['cash_in', 'cash_out', 'correction']);
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('register_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            // Clé d'idempotence fournie par le client (POS-11)
            $table->string('idempotency_key', 64)->unique();
            $table->string('ticket_number', 30)->nullable(); // attribué à la finalisation
            $table->enum('status', ['draft', 'on_hold', 'finalized', 'cancelled'])->default('draft');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('rounding_adjustment', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->decimal('change_given', 14, 2)->default(0);
            $table->timestamp('held_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'ticket_number']);
            $table->index(['pharmacy_id', 'finalized_at']);
            $table->index(['cash_session_id', 'status']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('presentation_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->enum('sale_unit', ['pack', 'blister', 'unit'])->default('pack');
            $table->unsignedInteger('quantity'); // dans l'unité de vente
            $table->unsignedInteger('quantity_base_units');
            $table->decimal('unit_price', 14, 4);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();

            $table->unique(['sale_id', 'line_no']);
        });

        // Affectation des lots à chaque ligne (FEFO proposé, lot confirmé)
        Schema::create('sale_item_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('batch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_base_units');
            $table->decimal('unit_cost', 14, 4);
            $table->boolean('fefo_overridden')->default(false);
            $table->foreignUlid('override_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('sale_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->decimal('amount', 14, 2); // négatif pour un remboursement
            $table->decimal('tendered', 14, 2)->nullable();
            $table->string('reference', 100)->nullable(); // saisie manuelle, pas une preuve
            $table->enum('kind', ['payment', 'refund'])->default('payment');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cash_session_id', 'payment_method_id']);
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('sale_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('cash_session_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->string('credit_note_number', 30);
            $table->enum('refund_mode', ['cash_refund', 'credit_note', 'exchange']);
            $table->decimal('total', 14, 2);
            $table->string('reason');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'credit_note_number']);
        });

        // Un retour ne réintègre jamais automatiquement le stock vendable
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('sale_item_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_base_units');
            $table->decimal('amount', 14, 2);
            $table->enum('disposition', ['quarantine', 'restock_approved', 'destroy'])->default('quarantine');
            $table->timestamps();
        });

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
        });
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_item_batches');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('payment_methods');
    }
};
