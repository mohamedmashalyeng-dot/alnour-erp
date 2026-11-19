<?php

namespace App\Modules\Optical\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable loyalty points ledger. */
class LoyaltyTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['customer_id', 'branch_id', 'type', 'points', 'balance_after', 'source_type', 'source_id', 'notes', 'user_id'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'balance_after' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The loyalty ledger is immutable.'));
        static::deleting(fn () => throw new LogicException('The loyalty ledger is immutable.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
