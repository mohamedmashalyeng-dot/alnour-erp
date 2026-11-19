<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Core\Support\DateOnly;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable salary history. */
class SalaryChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['employee_id', 'old_salary', 'new_salary', 'effective_on', 'reason', 'changed_by'];

    protected function casts(): array
    {
        return ['old_salary' => 'integer', 'new_salary' => 'integer', 'effective_on' => DateOnly::class];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Salary history is immutable.'));
        static::deleting(fn () => throw new LogicException('Salary history is immutable.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
