<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR module: extends the existing `employees` table (Phase 3 payroll) instead of creating a parallel one,
 * and adds attendance, leave, immutable salary-change history and performance reviews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employee_code', 32)->nullable()->unique()->after('id');
            $table->text('national_id')->nullable()->comment('encrypted at rest (sensitive)');
            $table->string('email')->nullable();
            $table->text('address')->nullable()->comment('encrypted at rest (sensitive)');
            $table->string('department', 100)->nullable();
            $table->string('employment_type', 16)->default('full_time')->comment('full_time|part_time|contract');
            $table->string('employment_status', 16)->default('active')->index()->comment('active|on_leave|suspended|terminated');
            $table->date('terminated_on')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->text('emergency_contact_phone')->nullable()->comment('encrypted at rest (sensitive)');
            $table->unsignedSmallInteger('annual_leave_days')->default(21);
            $table->time('shift_start')->nullable()->comment('expected start, for lateness');
            $table->text('notes')->nullable();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('status', 16)->comment('present|late|absent|half_day|leave|holiday');
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->string('source', 8)->default('manual')->comment('manual|self');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index(['branch_id', 'work_date']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('type', 16)->comment('annual|sick|unpaid|other');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days');
            $table->string('reason', 500)->nullable();
            $table->string('status', 16)->default('pending')->index()->comment('pending|approved|rejected|cancelled');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'start_date']);
        });

        // Immutable: every base-salary change, with who/when/why.
        Schema::create('salary_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('old_salary');
            $table->unsignedBigInteger('new_salary');
            $table->date('effective_on');
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('rating')->comment('1..5');
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('payroll_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->unsignedSmallInteger('unpaid_leave_days')->default(0);
            $table->unsignedBigInteger('attendance_deduction')->default(0)->comment('part of deductions, from attendance/unpaid leave');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn(['absent_days', 'unpaid_leave_days', 'attendance_deduction']);
        });
        foreach (['performance_reviews', 'salary_changes', 'leave_requests', 'attendance_records'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['employee_code']);
            $table->dropIndex(['employment_status']);
            $table->dropColumn(['employee_code', 'national_id', 'email', 'address', 'department', 'employment_type', 'employment_status',
                'terminated_on', 'emergency_contact_name', 'emergency_contact_phone', 'annual_leave_days', 'shift_start', 'notes']);
        });
    }
};
