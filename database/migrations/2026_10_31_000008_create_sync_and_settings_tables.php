<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Synchronisation outbox/inbox (PRD §13.3), paramètres et alertes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Écrit dans la même transaction que l'opération métier
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->string('aggregate_type', 50);
            $table->char('aggregate_id', 26);
            $table->string('event_type', 100);
            $table->unsignedBigInteger('sequence');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['aggregate_type', 'aggregate_id', 'sequence']);
            $table->index(['acknowledged_at', 'occurred_at']);
        });

        // Côté cloud (et local pour les propositions de référentiel) : dédup par event_id
        Schema::create('inbox_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->char('pharmacy_id', 26);
            $table->string('aggregate_type', 50);
            $table->char('aggregate_id', 26);
            $table->string('event_type', 100);
            $table->unsignedBigInteger('sequence');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->enum('status', ['received', 'applied', 'pending_review', 'rejected'])->default('received');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();

            $table->index(['pharmacy_id', 'aggregate_type', 'aggregate_id']);
        });

        Schema::create('sync_checkpoints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->enum('direction', ['push', 'pull']);
            $table->string('stream', 50);
            $table->string('last_cursor')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'direction', 'stream']);
        });

        Schema::create('sync_errors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('pharmacy_id', 26);
            $table->uuid('event_id')->nullable();
            $table->string('error_code', 50);
            $table->text('message');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Clés d'idempotence génériques pour les mutations API
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->char('user_id', 26)->nullable();
            $table->string('endpoint', 150);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->index();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->string('key', 100);
            $table->json('value');
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'key']);
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['expiry', 'low_stock', 'sync_failure', 'backup_failure', 'cash_variance']);
            $table->json('parameters'); // ex. {"days": [30, 60, 90, 180]}
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('sync_errors');
        Schema::dropIfExists('sync_checkpoints');
        Schema::dropIfExists('inbox_events');
        Schema::dropIfExists('outbox_events');
    }
};
