<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\FrameMaterial;
use App\Modules\Catalog\Enums\FrameType;
use App\Modules\Catalog\Enums\Gender;
use App\Modules\Catalog\Enums\LensType;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Core\Models\TaxRate;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Suppliers\Models\Supplier;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[UseFactory(ProductFactory::class)]
class Product extends Model
{
    use HasFactory;

    /** cost_price is intentionally not fillable: only the costing engine changes it. */
    protected $fillable = [
        'name', 'name_en', 'sku', 'barcode', 'qr_code', 'product_type', 'category_id', 'brand_id', 'supplier_id',
        'model', 'color', 'size', 'frame_type', 'frame_material', 'lens_type', 'gender', 'specifications',
        'purchase_price', 'selling_price', 'tax_rate_id', 'description', 'track_stock', 'is_active', 'warranty_months',
    ];

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'frame_type' => FrameType::class,
            'frame_material' => FrameMaterial::class,
            'lens_type' => LensType::class,
            'gender' => Gender::class,
            'specifications' => 'array',
            'purchase_price' => 'integer',
            'selling_price' => 'integer',
            'cost_price' => 'integer',
            'track_stock' => 'boolean',
            'warranty_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Search by name, SKU, barcode, QR code or model. Exact code matches are ranked first by callers.
     */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $q->where(function (Builder $w) use ($term, $like) {
            $w->where('sku', $term)
                ->orWhere('barcode', $term)
                ->orWhere('qr_code', $term)
                ->orWhere('name', 'like', $like)
                ->orWhere('name_en', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('barcode', 'like', $like)
                ->orWhere('model', 'like', $like);
        });
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name;
    }
}
