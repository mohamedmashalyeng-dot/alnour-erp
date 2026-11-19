<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 64)->index();
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->unsignedInteger('rate_bp')->comment('Basis points: 1500 = 15.00%');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('tax_rate_id')->references('id')->on('tax_rates')->nullOnDelete();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->unsignedBigInteger('branch_id')->default(0)->comment('0 = company-wide');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['type', 'branch_id', 'year']);
        });

        // Prepared for Phase 5 (Excel / CSV / Google Sheets). Not active in Phase 1.
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity', 32)->comment('products, inventory, customers, ...');
            $table->string('source_type', 16)->comment('xlsx, csv, google_sheet, url');
            $table->string('source_name')->nullable();
            $table->string('source_path')->nullable();
            $table->string('source_checksum', 64)->nullable()->index();
            $table->string('status', 16)->default('pending')->index();
            $table->string('duplicate_strategy', 16)->default('skip')->comment('skip, update, new_only');
            $table->json('column_mapping')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('error_report_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
        Schema::dropIfExists('document_sequences');
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['tax_rate_id']);
        });
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_logs');
    }
};
