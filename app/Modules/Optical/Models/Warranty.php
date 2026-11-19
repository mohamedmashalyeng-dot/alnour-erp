<?php

namespace App\Modules\Optical\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Support\DateOnly;
use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warranty extends Model
{
    protected $fillable = ['sale_item_id', 'sale_id', 'product_id', 'customer_id', 'branch_id', 'quantity', 'starts_on', 'ends_on', 'status', 'void_reason'];

    protected function casts(): array
    {
        return ['starts_on' => DateOnly::class, 'ends_on' => DateOnly::class, 'quantity' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    public function isValidOn(?string $date = null): bool
    {
        $date ??= today()->toDateString();

        return $this->status === 'active' && $this->starts_on->toDateString() <= $date && $this->ends_on->toDateString() >= $date;
    }
}
