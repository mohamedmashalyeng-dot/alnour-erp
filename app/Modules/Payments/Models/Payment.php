<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A money movement. direction "in" = received from a customer; "out" = paid to a supplier
 * or refunded to a customer. Payments are never edited or deleted.
 */
class Payment extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'tendered_amount' => 'integer',
            'change_amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Payment $p) {
            // Only status may change (for future voiding through a reversal workflow).
            if (array_diff(array_keys($p->getDirty()), ['status', 'updated_at'])) {
                throw new LogicException('Payments are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Payments are immutable.'));
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }
}
