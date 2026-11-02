<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS : un super-administrateur (sans officine) gère les pharmacies et leur abonnement ;
 * les identifiants de connexion ne sont plus uniques que dans une même pharmacie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('pharmacy_id');
            $table->dropUnique(['username']);
            $table->unique(['pharmacy_id', 'username']);
        });

        Schema::table('pharmacies', function (Blueprint $table) {
            $table->string('plan', 30)->nullable()->after('is_active');
            $table->date('subscription_ends_at')->nullable()->after('plan');
            $table->string('suspended_reason')->nullable()->after('subscription_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            $table->dropColumn(['plan', 'subscription_ends_at', 'suspended_reason']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pharmacy_id', 'username']);
            $table->unique('username');
            $table->dropColumn('is_super_admin');
        });
    }
};
