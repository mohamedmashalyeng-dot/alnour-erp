<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Sales\Enums\ItemCondition;
use App\Modules\Sales\Enums\ReturnDisposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    protected $fillable = ['sale_return_id', 'sale_item_id', 'product_id', 'quantity', 'amount', 'tax_amount', 'condition', 'disposition'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'amount' => 'integer', 'tax_amount' => 'integer',
            'condition' => ItemCondition::class, 'disposition' => ReturnDisposition::class, 'restocked' => 'boolean',
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
