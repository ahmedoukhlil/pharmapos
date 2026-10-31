<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel : médicament (DCI/marque) → présentation vendable → codes-barres (PRD §5).
 * Le stock est tenu en plus petite unité vendable (entier) ; une présentation
 * déclare combien d'unités de base elle contient (Annexe A).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['pharmacy_id', 'parent_id', 'name']);
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('type', ['medicine', 'parapharmacy']);
            $table->string('inn')->nullable(); // DCI
            $table->string('brand_name');
            $table->string('strength', 100)->nullable();
            $table->string('dosage_form', 100)->nullable();
            $table->string('route', 50)->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('regulatory_status', 50)->nullable(); // liste I/II, stupéfiant… à valider
            $table->boolean('prescription_required')->default(false);
            $table->string('storage_conditions')->nullable();
            $table->enum('status', ['active', 'suspended', 'withdrawn', 'archived'])->default('active');
            $table->timestamps();

            $table->index(['pharmacy_id', 'inn']);
            $table->index(['pharmacy_id', 'brand_name']);
        });

        Schema::create('presentations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('medicine_id')->constrained()->restrictOnDelete();
            $table->string('sku', 50);
            $table->string('label'); // ex. « Boîte de 3 plaquettes de 10 cp »
            $table->string('base_unit', 30)->default('unit'); // comprimé, flacon, plaquette…
            $table->unsignedInteger('units_per_pack')->default(1);
            $table->unsignedInteger('units_per_blister')->nullable();
            $table->boolean('allow_split_sale')->default(false);
            // Prix par conditionnement complet ; prix unitaire dérivé ou explicite
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->decimal('sale_price', 14, 2)->default(0);
            $table->decimal('unit_sale_price', 14, 4)->nullable();
            $table->decimal('weighted_avg_cost', 14, 4)->default(0); // CMUP par unité de base
            $table->string('pricing_regime', 50)->nullable();
            $table->unsignedInteger('reorder_threshold')->default(0); // en unités de base
            $table->unsignedInteger('reorder_quantity')->default(0);
            $table->enum('status', ['active', 'suspended', 'withdrawn', 'archived'])->default('active');
            $table->timestamps();

            $table->unique(['pharmacy_id', 'sku']);
        });

        // Codes-barres en chaîne pour préserver les zéros initiaux (MED-03)
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('presentation_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 64);
            $table->enum('symbology', ['ean13', 'ean8', 'upca', 'code128', 'gtin14', 'datamatrix', 'other']);
            $table->boolean('is_active')->default(true);
            // Colonne générée NULL quand inactif → unicité des seuls codes actifs
            $table->string('active_barcode', 64)->nullable()->storedAs('IF(is_active, barcode, NULL)');
            $table->timestamps();

            $table->index(['pharmacy_id', 'barcode']);
            $table->unique(['pharmacy_id', 'active_barcode']);
        });

        Schema::create('price_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('presentation_id')->constrained()->restrictOnDelete();
            $table->enum('price_type', ['purchase', 'sale', 'unit_sale']);
            $table->decimal('old_price', 14, 4)->nullable();
            $table->decimal('new_price', 14, 4);
            $table->foreignUlid('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('effective_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['presentation_id', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_history');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('presentations');
        Schema::dropIfExists('medicines');
        Schema::dropIfExists('categories');
    }
};
