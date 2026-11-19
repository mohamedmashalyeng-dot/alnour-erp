<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'product_id', 'quantity', 'unit_cost', 'discount_amount', 'tax_rate_bp', 'tax_amount', 'line_total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'unit_cost' => 'integer', 'discount_amount' => 'integer',
            'tax_rate_bp' => 'integer', 'tax_amount' => 'integer', 'line_total' => 'integer',
        ];
    }

    /** Net unit cost after discount, excluding tax (used for inventory costing). */
    public function netUnitCost(): int
    {
        return intdiv($this->quantity * $this->unit_cost - $this->discount_amount + intdiv($this->quantity, 2), max(1, $this->quantity));
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
