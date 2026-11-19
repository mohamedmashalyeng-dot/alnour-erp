<?php

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = ['number', 'branch_id', 'year', 'month', 'created_by'];

    protected function casts(): array
    {
        return ['total_gross' => 'integer', 'total_net' => 'integer', 'approved_at' => 'datetime', 'paid_at' => 'datetime', 'year' => 'integer', 'month' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
