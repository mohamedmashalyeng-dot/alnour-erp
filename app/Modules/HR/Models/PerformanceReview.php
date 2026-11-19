<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Core\Support\DateOnly;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends Model
{
    protected $fillable = ['employee_id', 'period_start', 'period_end', 'rating', 'strengths', 'improvements', 'reviewer_id'];

    protected function casts(): array
    {
        return ['period_start' => DateOnly::class, 'period_end' => DateOnly::class, 'rating' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
