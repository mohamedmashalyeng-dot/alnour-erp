<?php

namespace App\Modules\Treasury\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Support\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Cash/bank deposit, withdrawal or transfer. Immutable once recorded (corrections = new movement).
 */
class TreasuryMovement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['movement_date' => DateOnly::class, 'amount' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Treasury movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Treasury movements are immutable.'));
    }

    public function from(): MorphTo
    {
        return $this->morphTo();
    }

    public function to(): MorphTo
    {
        return $this->morphTo();
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
