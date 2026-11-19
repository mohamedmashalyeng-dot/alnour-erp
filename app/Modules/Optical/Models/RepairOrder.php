<?php

namespace App\Modules\Optical\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Support\DateOnly;
use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairOrder extends Model
{
    public const STATUSES = ['received', 'in_progress', 'ready', 'delivered', 'cancelled'];

    /** Allowed forward transitions (delivery goes through RepairService::deliver). */
    public const TRANSITIONS = [
        'received' => ['in_progress', 'ready', 'cancelled'],
        'in_progress' => ['ready', 'cancelled'],
        'ready' => ['in_progress', 'cancelled'],
    ];

    protected $fillable = ['number', 'branch_id', 'customer_id', 'warranty_claim_id', 'item_description', 'problem', 'status', 'estimated_cost',
        'final_cost', 'promised_on', 'sale_id', 'received_by', 'delivered_at', 'notes'];

    protected function casts(): array
    {
        return ['estimated_cost' => 'integer', 'final_cost' => 'integer', 'promised_on' => DateOnly::class, 'delivered_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(WarrantyClaim::class, 'warranty_claim_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
