<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = ['sale_id', 'product_id', 'product_name', 'sku', 'quantity', 'unit_price', 'discount_amount', 'tax_rate_bp', 'tax_amount', 'line_total', 'unit_cost'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'returned_quantity' => 'integer', 'unit_price' => 'integer', 'discount_amount' => 'integer',
            'tax_rate_bp' => 'integer', 'tax_amount' => 'integer', 'line_total' => 'integer', 'unit_cost' => 'integer',
        ];
    }

    public function returnableQuantity(): int
    {
        return $this->quantity - $this->returned_quantity;
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
