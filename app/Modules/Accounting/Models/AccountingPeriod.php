<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Core\Support\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    protected $fillable = ['year', 'month', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => DateOnly::class, 'ends_on' => DateOnly::class, 'closed_at' => 'datetime', 'year' => 'integer', 'month' => 'integer'];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
