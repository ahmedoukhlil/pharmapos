<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC par action, officine et emplacement ; journal d'audit (PRD §4, §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 50)->unique(); // owner, pharmacist_in_charge, cashier…
            $table->string('name');
            $table->boolean('requires_mfa')->default(false);
            // Plafond de remise en % autorisé sans réauthentification (Annexe A)
            $table->decimal('max_discount_percent', 5, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 100)->unique(); // sale.refund, batch.quarantine, price.update…
            $table->string('name');
            $table->boolean('requires_reauth')->default(false);
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // Affectation d'un rôle, éventuellement restreinte à un emplacement
        Schema::create('role_user', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'pharmacy_id', 'location_id']);
        });

        // Append-only : aucune mise à jour ni suppression par l'application
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 100);
            $table->string('auditable_type', 100)->nullable();
            $table->char('auditable_id', 26)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason')->nullable();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['pharmacy_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['pharmacy_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
