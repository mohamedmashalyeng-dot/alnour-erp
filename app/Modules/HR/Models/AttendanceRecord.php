<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Support\DateOnly;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    public const STATUSES = ['present', 'late', 'absent', 'half_day', 'holiday'];

    protected $fillable = ['employee_id', 'branch_id', 'work_date', 'check_in_at', 'check_out_at', 'status', 'late_minutes', 'worked_minutes', 'source', 'recorded_by', 'notes'];

    protected function casts(): array
    {
        return ['work_date' => DateOnly::class, 'check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'late_minutes' => 'integer', 'worked_minutes' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
