<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock level of one product in one branch.
 *
 * quantity / reserved_quantity are NOT mass-assignable: they may only be changed by
 * App\Modules\Inventory\Services\InventoryService, which records a transaction for every change.
 */
class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = ['branch_id', 'product_id', 'min_stock_level'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'reserved_quantity' => 'integer', 'min_stock_level' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function available(): int
    {
        return $this->quantity - $this->reserved_quantity;
    }

    public function isLow(): bool
    {
        return $this->min_stock_level > 0 && $this->quantity <= $this->min_stock_level;
    }

    public function scopeLowStock(Builder $q): Builder
    {
        return $q->where('min_stock_level', '>', 0)->whereColumn('quantity', '<=', 'min_stock_level');
    }
}
