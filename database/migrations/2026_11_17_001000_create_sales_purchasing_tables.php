<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: customers, purchasing, sales, payments, returns.
 * All money columns are integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->nullable()->unique();
            $table->string('name');
            $table->string('phone', 32)->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_walk_in')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete()->comment('Registering branch');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->string('payment_status', 16)->default('unpaid')->index();
            $table->string('supplier_invoice_number', 64)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->date('order_date');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'status']);
            $table->unique(['supplier_id', 'supplier_invoice_number'], 'po_supplier_invoice_unique');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('Client idempotency key (POS / offline sync)');
            $table->string('invoice_number', 32)->nullable()->unique()->comment('Assigned on completion');
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->string('payment_status', 16)->default('unpaid')->index();
            $table->string('return_status', 16)->default('none');
            $table->boolean('prices_include_tax')->default(false);
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('returned_amount')->default(0)->comment('Credit from completed returns');
            $table->unsignedBigInteger('refunded_amount')->default(0)->comment('Money paid back to customer');
            $table->text('notes')->nullable();
            $table->timestamp('sold_at')->nullable()->index();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'sold_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['cashier_id', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('sku', 64);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total')->comment('Final amount incl. tax, after discount');
            $table->unsignedBigInteger('unit_cost')->default(0)->comment('Cost snapshot for COGS');
            $table->timestamps();
            $table->index('product_id');
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 32)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 16)->default('return')->comment('return | exchange');
            $table->string('status', 16)->default('pending')->index();
            $table->string('reason');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total')->default(0)->comment('Credit granted');
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->unsignedBigInteger('exchange_credit_used')->default(0);
            $table->foreignId('exchange_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('amount')->comment('Credit incl. tax');
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->string('condition', 16)->default('good')->comment('good | damaged | defective');
            $table->string('disposition', 16)->default('restock')->comment('restock | write_off | supplier_return');
            $table->boolean('restocked')->default(false);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('Idempotency key');
            $table->string('number', 32)->unique();
            $table->string('direction', 3)->comment('in = received, out = paid/refunded');
            $table->string('party_type', 16)->comment('customer | supplier');
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('method', 24)->comment('cash | card | bank_transfer | cheque | store_credit');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('tendered_amount')->nullable();
            $table->unsignedBigInteger('change_amount')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('status', 16)->default('posted')->index();
            $table->foreignId('sale_return_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->index();
            $table->timestamps();
            $table->index(['customer_id', 'paid_at']);
            $table->index(['supplier_id', 'paid_at']);
            $table->index(['branch_id', 'paid_at']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->morphs('allocatable');
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->unique(['payment_id', 'allocatable_type', 'allocatable_id'], 'payment_alloc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('customers');
    }
};
