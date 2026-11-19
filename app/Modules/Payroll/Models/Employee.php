<?php

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Support\DateOnly;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\SalaryChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee master record shared by Payroll (salary, advances) and HR (profile, attendance, leave, reviews).
 * national_id, address and emergency_contact_phone are encrypted at rest and only shown with hr.sensitive.
 */
class Employee extends Model
{
    public const STATUSES = ['active', 'on_leave', 'suspended', 'terminated'];

    public const TYPES = ['full_time', 'part_time', 'contract'];

    /** Attributes visible only to users with hr.sensitive. */
    public const SENSITIVE = ['national_id', 'address', 'emergency_contact_phone'];

    protected $fillable = ['user_id', 'branch_id', 'name', 'job_title', 'phone', 'base_salary', 'hired_on', 'is_active',
        'employee_code', 'national_id', 'email', 'address', 'department', 'employment_type', 'employment_status', 'terminated_on',
        'emergency_contact_name', 'emergency_contact_phone', 'annual_leave_days', 'shift_start', 'notes'];

    protected $hidden = ['national_id', 'address', 'emergency_contact_phone'];

    protected function casts(): array
    {
        return [
            'base_salary' => 'integer', 'hired_on' => DateOnly::class, 'terminated_on' => DateOnly::class, 'is_active' => 'boolean',
            'annual_leave_days' => 'integer',
            'national_id' => 'encrypted', 'address' => 'encrypted', 'emergency_contact_phone' => 'encrypted',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function salaryChanges(): HasMany
    {
        return $this->hasMany(SalaryChange::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class);
    }

    public function outstandingAdvances(): int
    {
        return (int) $this->advances()->sum('amount') - (int) $this->advances()->sum('recovered_amount');
    }

    /** National ID shown as ••••1234 to users without hr.sensitive. */
    public function maskedNationalId(): ?string
    {
        $id = $this->national_id;

        return $id ? str_repeat('•', max(0, mb_strlen($id) - 4)).mb_substr($id, -4) : null;
    }
}
