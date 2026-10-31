<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal des sauvegardes (PRD §13.4) : succès, échecs et tests de restauration
        Schema::create('backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->enum('status', ['running', 'success', 'failed'])->default('running');
            $table->string('trigger', 20)->default('schedule'); // schedule | manual
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->char('checksum', 64)->nullable(); // sha256 du fichier chiffré
            $table->boolean('secondary_copied')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('restore_tested_at')->nullable();
            $table->string('restore_result')->nullable();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
