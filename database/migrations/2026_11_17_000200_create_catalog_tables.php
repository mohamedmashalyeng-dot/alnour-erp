<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->nullable()->unique();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('phone', 32)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('code', 32)->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->unique();
            $table->string('qr_code', 191)->nullable()->unique();
            $table->string('product_type', 32)->default('frame')->index();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 100)->nullable()->index();
            $table->string('color', 64)->nullable();
            $table->string('size', 32)->nullable();
            // Eyewear specifics
            $table->string('frame_type', 32)->nullable();
            $table->string('frame_material', 32)->nullable();
            $table->string('lens_type', 32)->nullable();
            $table->string('gender', 16)->nullable();
            $table->json('specifications')->nullable();
            // Money in integer minor units (e.g. halalas / cents). Never floats.
            $table->unsignedBigInteger('purchase_price')->default(0);
            $table->unsignedBigInteger('selling_price')->default(0);
            $table->unsignedBigInteger('cost_price')->default(0)->comment('Moving cost per costing method');
            $table->foreignId('tax_rate_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->boolean('track_stock')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category_id', 'brand_id']);
            $table->index('name');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('mime', 64);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('suppliers');
    }
};
