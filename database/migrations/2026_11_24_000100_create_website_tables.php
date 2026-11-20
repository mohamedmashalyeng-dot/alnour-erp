<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public website (product showcase) connected to the ERP catalog:
 * - products.show_on_website / website_featured: which products the public site lists (off by default).
 * - website_inquiries: order / eye-exam / contact requests sent from the site, handled from the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('show_on_website')->default(false)->index();
            $table->boolean('website_featured')->default(false);
        });

        Schema::create('website_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->index();               // product | exam | contact
            $table->string('status', 16)->default('new')->index(); // new | contacted | done | spam
            $table->string('name', 100);
            $table->string('phone', 32)->index();
            $table->text('message')->nullable();               // encrypted (may contain health details)
            $table->date('preferred_date')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->text('staff_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_inquiries');
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['show_on_website']);
            $table->dropColumn(['show_on_website', 'website_featured']);
        });
    }
};
