<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eyewear module: prescriptions, warranties & claims, repair orders and customer loyalty.
 * Lenses reuse the existing catalog (product_type = ophthalmic_lens / contact_lens + specification ranges).
 *
 * Optical values are stored as integers: SPH/CYL/ADD in hundredths of a dioptre (-2.25 → -225),
 * AXIS in degrees (1–180), PD in tenths of a millimetre (63.5 → 635). No floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('type', 16)->default('spectacles')->comment('spectacles|contact_lens');
            $table->date('examined_on');
            $table->date('expires_on');
            $table->string('examiner_name')->nullable();
            foreach (['od', 'os'] as $eye) {
                $table->smallInteger($eye.'_sph')->nullable();
                $table->smallInteger($eye.'_cyl')->nullable();
                $table->unsignedSmallInteger($eye.'_axis')->nullable();
                $table->smallInteger($eye.'_add')->nullable();
            }
            $table->unsignedSmallInteger('pd')->nullable()->comment('binocular distance PD, 0.1 mm');
            $table->unsignedSmallInteger('pd_right')->nullable();
            $table->unsignedSmallInteger('pd_left')->nullable();
            $table->unsignedSmallInteger('cl_base_curve')->nullable()->comment('contact lens BC, 0.01 mm');
            $table->unsignedSmallInteger('cl_diameter')->nullable()->comment('contact lens DIA, 0.01 mm');
            $table->text('medical_notes')->nullable()->comment('encrypted at rest');
            $table->string('status', 16)->default('active')->comment('active|void');
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'examined_on']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('prescription_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('loyalty_points_earned')->default(0);
            $table->unsignedInteger('loyalty_points_redeemed')->default(0);
            $table->unsignedBigInteger('loyalty_discount')->default(0)->comment('part of discount_total');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->default(0);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points')->default(0)->comment('current balance; ledger = loyalty_transactions');
            $table->unsignedInteger('loyalty_lifetime_points')->default(0)->comment('earned in total, drives the tier');
        });

        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->date('starts_on');
            $table->date('ends_on')->index();
            $table->string('status', 16)->default('active')->comment('active|void');
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('warranty_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->text('issue');
            $table->string('status', 16)->default('open')->index()->comment('open|approved|rejected|resolved');
            $table->string('resolution', 16)->nullable()->comment('repair|replace|refund|none');
            $table->text('resolution_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('repair_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('warranty_claim_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_description');
            $table->text('problem');
            $table->string('status', 16)->default('received')->index()->comment('received|in_progress|ready|delivered|cancelled');
            $table->unsignedBigInteger('estimated_cost')->default(0);
            $table->unsignedBigInteger('final_cost')->nullable();
            $table->date('promised_on')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete()->comment('invoice for a charged repair');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Immutable points ledger. One entry per (source, type) makes every posting idempotent.
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16)->comment('earn|redeem|reverse_earn|restore|adjust');
            $table->integer('points')->comment('signed');
            $table->unsignedInteger('balance_after');
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['source_type', 'source_id', 'type']);
            $table->index(['customer_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['loyalty_transactions', 'repair_orders', 'warranty_claims', 'warranties'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['loyalty_points', 'loyalty_lifetime_points']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('warranty_months'));
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prescription_id');
            $table->dropColumn(['loyalty_points_earned', 'loyalty_points_redeemed', 'loyalty_discount']);
        });
        Schema::dropIfExists('prescriptions');
    }
};
