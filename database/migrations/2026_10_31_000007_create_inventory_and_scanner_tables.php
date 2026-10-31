<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventaires (PRD §9) et sessions de scanner smartphone (PRD §8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->enum('scope', ['full', 'partial', 'cycle']);
            $table->enum('theoretical_mode', ['frozen', 'adjusted'])->default('frozen'); // STK-05
            $table->enum('status', ['draft', 'counting', 'review', 'approved', 'cancelled'])->default('draft');
            $table->timestamp('snapshot_at')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'number']);
        });

        Schema::create('inventory_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('inventory_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('presentation_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->bigInteger('theoretical_quantity')->default(0);
            $table->bigInteger('counted_quantity')->nullable();
            $table->bigInteger('recount_quantity')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->decimal('variance_value', 14, 2)->nullable();
            $table->foreignUlid('counted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['inventory_session_id', 'batch_id', 'location_id'], 'inv_line_unique');
        });

        Schema::create('inventory_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('inventory_session_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('approved_by')->constrained('users')->restrictOnDelete();
            $table->enum('decision', ['recount', 'approved', 'rejected']);
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // QR d'appairage à usage unique, expirant (QA-15, QA-16)
        Schema::create('scanner_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('register_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('mode', ['pos', 'inventory', 'receipt', 'lookup']);
            $table->char('context_id', 26)->nullable(); // inventaire ou réception ciblé
            $table->string('pairing_token_hash', 128)->unique();
            $table->timestamp('pairing_expires_at');
            $table->timestamp('paired_at')->nullable();
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // Déduplication réseau par scan_event_id (SCN-05)
        Schema::create('scan_events', function (Blueprint $table) {
            $table->uuid('id')->primary(); // scan_event_id généré par le téléphone
            $table->foreignUlid('scanner_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('barcode', 64);
            $table->foreignUlid('presentation_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('status', ['received', 'applied', 'unknown_product', 'rejected'])->default('received');
            $table->timestamp('scanned_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['scanner_session_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_events');
        Schema::dropIfExists('scanner_sessions');
        Schema::dropIfExists('inventory_approvals');
        Schema::dropIfExists('inventory_lines');
        Schema::dropIfExists('inventory_sessions');
    }
};
