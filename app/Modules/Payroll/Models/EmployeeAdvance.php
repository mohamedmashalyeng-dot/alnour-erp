<?php

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Modules\Core\Support\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAdvance extends Model
{
    protected $fillable = ['uuid', 'employee_id', 'advance_date', 'amount', 'payment_source', 'cash_register_id', 'bank_account_id', 'notes', 'user_id'];

    protected function casts(): array
    {
        return ['advance_date' => DateOnly::class, 'amount' => 'integer', 'recovered_amount' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
