<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Droits propres à un utilisateur, par-dessus ceux de son rôle :
 * granted = true ajoute la permission, false la retire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_user', function (Blueprint $table) {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->boolean('granted');
            $table->timestamps();
            $table->primary(['user_id', 'permission_id', 'pharmacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_user');
    }
};
