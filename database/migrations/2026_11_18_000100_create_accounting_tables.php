<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: double-entry accounting, cash & bank, expenses, payroll.
 * Purely additive: existing tables only gain nullable columns.
 * All money columns are integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('description')->nullable();
            $table->string('description_en')->nullable();
            $table->string('type', 16)->index()->comment('asset|liability|equity|revenue|cogs|expense');
            $table->string('normal_balance', 6)->comment('debit|credit');
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false)->comment('Seeded/required account: cannot be removed');
            $table->timestamps();
        });

        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique()->comment('e.g. sales.revenue, vat.output');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 8)->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->date('entry_date')->index();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_event', 40)->nullable();
            $table->string('status', 10)->default('posted')->index()->comment('draft|posted|reversed');
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('memo')->nullable();
            $table->unsignedBigInteger('total')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            // Idempotent posting: one entry per (document, event).
            $table->unique(['source_type', 'source_id', 'source_event'], 'journal_source_unique');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('debit')->default(0);
            $table->unsignedBigInteger('credit')->default(0);
            $table->string('party_type', 16)->nullable()->comment('customer|supplier|employee');
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['account_id', 'branch_id']);
            $table->index(['party_type', 'party_id']);
        });

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->foreignId('account_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 8)->default('open')->index();
            $table->unsignedBigInteger('opening_float')->default(0);
            $table->timestamp('opened_at');
            $table->unsignedBigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('counted_cash')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('account_number', 64)->nullable();
            $table->string('iban', 64)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete()->comment('Branch dimension for postings (null = head office branch)');
            $table->foreignId('account_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_register_id')->nullable()->after('method')->constrained()->nullOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->after('cash_register_id')->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->after('cash_shift_id')->constrained()->nullOnDelete();
        });

        // Deposits, withdrawals and transfers between cash registers / bank accounts.
        Schema::create('treasury_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 32)->unique();
            $table->string('type', 16)->comment('deposit|withdrawal|transfer');
            $table->date('movement_date');
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('from');
            $table->nullableMorphs('to');
            $table->foreignId('counter_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->date('statement_date');
            $table->bigInteger('statement_balance');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_line_id')->unique()->constrained()->restrictOnDelete();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 32)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->date('expense_date');
            $table->string('description');
            $table->unsignedBigInteger('amount')->comment('Net of tax');
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total');
            $table->string('payment_source', 16)->comment('cash|bank|payable');
            $table->foreignId('cash_register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('status', 12)->default('draft')->index()->comment('draft|submitted|approved|rejected');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'expense_date']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Financial side of employees; the later HR module extends this same table.
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('job_title')->nullable();
            $table->string('phone', 32)->nullable();
            $table->unsignedBigInteger('base_salary')->default(0);
            $table->date('hired_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('advance_date');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('recovered_amount')->default(0);
            $table->string('payment_source', 8)->comment('cash|bank');
            $table->foreignId('cash_register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 10)->default('draft')->comment('draft|approved|paid');
            $table->unsignedBigInteger('total_gross')->default(0);
            $table->unsignedBigInteger('total_net')->default(0);
            $table->string('payment_source', 8)->nullable();
            $table->foreignId('cash_register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'year', 'month']);
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('base_salary');
            $table->unsignedBigInteger('bonuses')->default(0);
            $table->unsignedBigInteger('deductions')->default(0);
            $table->unsignedBigInteger('advance_recovery')->default(0);
            $table->unsignedBigInteger('net_pay');
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['payroll_items', 'payroll_runs', 'employee_advances', 'employees', 'attachments', 'expenses', 'expense_categories',
            'bank_reconciliation_lines', 'bank_reconciliations', 'treasury_movements'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropConstrainedForeignId('cash_shift_id');
            $table->dropConstrainedForeignId('cash_register_id');
        });
        foreach (['bank_accounts', 'cash_shifts', 'cash_registers', 'journal_lines', 'journal_entries', 'accounting_periods', 'account_mappings', 'accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
