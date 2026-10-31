<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fournisseurs, commandes, réceptions, lots, factures et règlements (PRD §6, §10).
 * Les lots sont créés à la réception (LOT-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('nif', 30)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['pharmacy_id', 'code']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->enum('status', ['draft', 'sent', 'partially_received', 'received', 'cancelled'])->default('draft');
            $table->date('ordered_at')->nullable();
            $table->date('expected_at')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'number']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('presentation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_packs');
            $table->unsignedInteger('received_packs')->default(0);
            $table->decimal('unit_price', 14, 2);
            $table->timestamps();
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('delivery_note_ref', 50)->nullable();
            $table->enum('status', ['draft', 'validated', 'cancelled'])->default('draft');
            $table->timestamp('received_at');
            $table->foreignUlid('received_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'number']);
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('presentation_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('goods_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('lot_number', 50);
            // Date saisie telle qu'imprimée + date effective calculée (Annexe A : dernier jour du mois)
            $table->string('expiry_raw', 20)->nullable();
            $table->date('expiry_date');
            $table->decimal('unit_cost', 14, 4)->default(0); // par unité de base
            $table->date('received_on')->nullable();
            $table->enum('status', ['available', 'reserved', 'quarantine', 'recalled', 'expired', 'destroyed', 'returned'])
                ->default('available');
            $table->boolean('is_initial_import')->default(false); // lot INIT-<date> de migration
            $table->timestamps();

            $table->unique(['pharmacy_id', 'presentation_id', 'lot_number', 'expiry_date'], 'batches_lot_unique');
            $table->index(['pharmacy_id', 'expiry_date']);
            $table->index(['presentation_id', 'status', 'expiry_date']); // FEFO
        });

        Schema::create('batch_status_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('batch_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('reason');
            $table->foreignUlid('changed_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_order_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('presentation_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_packs');
            $table->unsignedInteger('quantity_units'); // = packs × units_per_pack
            $table->decimal('unit_price', 14, 2); // par conditionnement
            $table->enum('decision', ['accepted', 'quarantine', 'refused'])->default('accepted');
            $table->string('anomaly')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_number', 50);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->enum('status', ['open', 'partially_paid', 'paid', 'cancelled'])->default('open');
            $table->timestamps();

            $table->unique(['pharmacy_id', 'supplier_id', 'supplier_invoice_number'], 'purchase_invoices_number_unique');
        });

        Schema::create('purchase_invoice_receipts', function (Blueprint $table) {
            $table->foreignUlid('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->primary(['purchase_invoice_id', 'goods_receipt_id']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 30);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_invoice_receipts');
        Schema::dropIfExists('purchase_invoices');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('batch_status_history');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
