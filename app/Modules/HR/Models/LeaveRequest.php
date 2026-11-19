<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Support\DateOnly;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    public const TYPES = ['annual', 'sick', 'unpaid', 'other'];

    protected $fillable = ['employee_id', 'branch_id', 'type', 'start_date', 'end_date', 'days', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['start_date' => DateOnly::class, 'end_date' => DateOnly::class, 'days' => 'integer', 'decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
