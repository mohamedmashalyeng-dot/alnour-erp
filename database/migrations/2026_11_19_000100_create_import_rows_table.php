<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: staged spreadsheet rows (validated before anything touches the catalog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->json('headers')->nullable()->after('column_mapping');
            $table->unsignedInteger('valid_count')->default(0)->after('total_rows');
            $table->unsignedInteger('invalid_count')->default(0)->after('valid_count');
            $table->unsignedInteger('duplicate_count')->default(0)->after('invalid_count');
            $table->unsignedInteger('validated_rows')->default(0)->after('duplicate_count');
            $table->string('last_error')->nullable();
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_log_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('data');
            $table->string('status', 16)->default('pending')->comment('pending|valid|invalid|duplicate|imported|updated|skipped|failed');
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->string('sku_key', 64)->nullable();
            $table->string('barcode_key', 64)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['import_log_id', 'row_number']);
            $table->index(['import_log_id', 'status']);
            $table->index(['import_log_id', 'sku_key']);
            $table->index(['import_log_id', 'barcode_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropColumn(['headers', 'valid_count', 'invalid_count', 'duplicate_count', 'validated_rows', 'last_error']);
        });
    }
};
