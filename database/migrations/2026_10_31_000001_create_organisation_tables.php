<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisation : officine → emplacements, caisses, appareils (PRD §9, §15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('nif', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->char('currency', 3)->default('MRU');
            $table->string('timezone', 40)->default('Africa/Nouakchott');
            $table->string('license_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Arborescence officine → réserve/rayon → emplacement
        Schema::create('locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->enum('type', ['store', 'reserve', 'shelf', 'bin', 'quarantine'])->default('shelf');
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['pharmacy_id', 'code']);
        });

        Schema::create('registers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->enum('printer_width', ['58', '80'])->default('80');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['pharmacy_id', 'code']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->enum('type', ['pos_pc', 'smartphone', 'server', 'printer']);
            $table->string('token_hash', 128)->nullable()->unique();
            $table->string('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUlid('pharmacy_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pharmacy_id');
        });
        Schema::dropIfExists('devices');
        Schema::dropIfExists('registers');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('pharmacies');
    }
};
