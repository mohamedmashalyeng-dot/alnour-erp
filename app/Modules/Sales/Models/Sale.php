<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Optical\Models\Prescription;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Sales\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Money fields and statuses are maintained exclusively by SaleService / PaymentService / ReturnService.
 *
 * balance due = total - returned_amount - paid_amount + refunded_amount
 *   > 0 : customer owes the business
 *   < 0 : business owes the customer (refund or store credit available)
 */
class Sale extends Model
{
    protected $fillable = ['uuid', 'branch_id', 'cashier_id', 'customer_id', 'notes'];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'payment_status' => PaymentStatus::class,
            'prices_include_tax' => 'boolean',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'paid_amount' => 'integer',
            'returned_amount' => 'integer',
            'refunded_amount' => 'integer',
            'sold_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'needs_review' => 'boolean', 'loyalty_points_earned' => 'integer', 'loyalty_points_redeemed' => 'integer', 'loyalty_discount' => 'integer',
        ];
    }

    public function balanceDue(): int
    {
        return $this->total - $this->returned_amount - $this->paid_amount + $this->refunded_amount;
    }

    /** Amount that can still be refunded / used as store credit. */
    public function refundable(): int
    {
        return max(0, -$this->balanceDue());
    }

    public function netTotal(): int
    {
        return $this->total - $this->returned_amount;
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }
}
