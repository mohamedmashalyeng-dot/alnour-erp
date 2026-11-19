<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: offline POS devices, synchronization log and conflicts for manual review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->comment('SHA-256 of the device secret held in an HttpOnly cookie');
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('channel', 8)->default('pos')->after('status')->comment('pos | offline');
            $table->foreignId('device_id')->nullable()->after('channel')->constrained('pos_devices')->nullOnDelete();
            $table->boolean('needs_review')->default(false)->after('device_id')->index();
        });

        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('client_uuid')->index();
            $table->string('status', 16)->comment('synced | duplicate | conflict | failed | rejected');
            $table->string('message')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('client_uuid')->index();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32)->comment('insufficient_stock | price_changed | rejected | other');
            $table->json('details')->nullable();
            $table->json('payload')->nullable()->comment('Original offline sale, kept for manual re-entry when rejected');
            $table->string('status', 12)->default('open')->index();
            $table->string('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_logs');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropColumn(['channel', 'needs_review']);
        });
        Schema::dropIfExists('pos_devices');
    }
};
