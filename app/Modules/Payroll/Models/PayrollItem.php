<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $fillable = ['payroll_run_id', 'employee_id', 'base_salary', 'bonuses', 'deductions', 'advance_recovery', 'net_pay', 'notes', 'absent_days', 'unpaid_leave_days', 'attendance_deduction'];

    protected function casts(): array
    {
        return ['base_salary' => 'integer', 'bonuses' => 'integer', 'deductions' => 'integer', 'advance_recovery' => 'integer', 'net_pay' => 'integer', 'absent_days' => 'integer', 'unpaid_leave_days' => 'integer', 'attendance_deduction' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
