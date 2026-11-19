<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Support\DateOnly;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Totals, status and paid_amount are written only by PurchaseService / PaymentService.
 */
class PurchaseOrder extends Model
{
    protected $fillable = ['supplier_id', 'branch_id', 'supplier_invoice_number', 'supplier_invoice_date', 'order_date', 'notes'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'payment_status' => PaymentStatus::class,
            'order_date' => DateOnly::class,
            'supplier_invoice_date' => DateOnly::class,
            'confirmed_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'paid_amount' => 'integer',
        ];
    }

    public function balanceDue(): int
    {
        return $this->total - $this->paid_amount;
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }
}
