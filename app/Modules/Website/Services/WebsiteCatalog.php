<?php

namespace App\Modules\Website\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Support\Money;
use App\Modules\Inventory\Models\Inventory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only queries for the public website. Only active products marked "show on website" are ever
 * returned, and only public columns are selected (never purchase/cost prices or suppliers).
 */
class WebsiteCatalog
{
    public const PUBLIC_COLUMNS = ['id', 'name', 'name_en', 'sku', 'product_type', 'category_id', 'brand_id', 'model', 'color', 'size',
        'frame_type', 'frame_material', 'lens_type', 'gender', 'selling_price', 'description', 'track_stock', 'warranty_months',
        'website_featured', 'created_at'];

    public const SORTS = ['newest', 'price_asc', 'price_desc', 'name'];

    public function base(): Builder
    {
        return Product::query()->onWebsite()
            ->where('product_type', '!=', ProductType::Service->value)
            ->select(self::PUBLIC_COLUMNS)
            ->with(['primaryImage', 'brand:id,name,name_en', 'category:id,name,name_en']);
    }

    /** @param array<string, mixed> $f */
    public function search(array $f): LengthAwarePaginator
    {
        $q = $this->base();

        if (filled($f['q'] ?? null)) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $f['q'])).'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('name_en', 'like', $like)
                ->orWhere('model', 'like', $like)->orWhere('sku', 'like', $like)
                ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like)->orWhere('name_en', 'like', $like)));
        }
        foreach (['category' => 'category_id', 'brand' => 'brand_id'] as $param => $column) {
            if (filled($f[$param] ?? null)) {
                $q->where($column, (int) $f[$param]);
            }
        }
        foreach (['type' => 'product_type', 'gender' => 'gender', 'frame' => 'frame_type'] as $param => $column) {
            if (filled($f[$param] ?? null)) {
                $q->where($column, (string) $f[$param]);
            }
        }
        foreach (['min' => '>=', 'max' => '<='] as $param => $op) {
            $v = trim((string) ($f[$param] ?? ''));
            if ($v !== '' && Money::isValid($v)) {
                $q->where('selling_price', $op, Money::parse($v));
            }
        }
        if (! empty($f['available'])) {
            $q->where(fn ($w) => $w->where('track_stock', false)->orWhereIn('id', $this->inStockQuery()));
        }

        match ($f['sort'] ?? 'newest') {
            'price_asc' => $q->orderBy('selling_price'),
            'price_desc' => $q->orderByDesc('selling_price'),
            'name' => $q->orderBy('name'),
            default => $q->orderByDesc('website_featured')->orderByDesc('id'),
        };

        return $q->paginate(config('erp.website.per_page', 24))->withQueryString();
    }

    public function find(int $id): Product
    {
        return $this->base()->with(['images' => fn ($i) => $i->orderByDesc('is_primary')->orderBy('sort_order')])->findOrFail($id);
    }

    public function featured(int $limit = 8): Collection
    {
        $featured = $this->base()->where('website_featured', true)->latest('id')->limit($limit)->get();

        return $featured->isNotEmpty() ? $featured : $this->base()->latest('id')->limit($limit)->get();
    }

    public function related(Product $product, int $limit = 4): Collection
    {
        return $this->base()->whereKeyNot($product->id)
            ->where(fn ($w) => $w->where('category_id', $product->category_id)->orWhere('brand_id', $product->brand_id))
            ->inRandomOrder()->limit($limit)->get();
    }

    /** Categories and brands that have at least one published product, with counts. */
    public function facets(): array
    {
        $ids = $this->base()->reorder()->select('id');
        $count = fn (string $column) => Product::whereIn('id', $ids)->whereNotNull($column)
            ->selectRaw("$column as id, count(*) as n")->groupBy($column)->pluck('n', 'id');

        $catCounts = $count('category_id');
        $brandCounts = $count('brand_id');
        $typeCounts = Product::whereIn('id', $ids)->selectRaw('product_type as t, count(*) as n')->groupBy('product_type')->pluck('n', 't');

        return [
            'categories' => Category::whereIn('id', $catCounts->keys())->where('is_active', true)->orderBy('name')->get()
                ->each(fn ($c) => $c->setAttribute('products_count', $catCounts[$c->id])),
            'brands' => Brand::whereIn('id', $brandCounts->keys())->where('is_active', true)->orderBy('name')->get()
                ->each(fn ($b) => $b->setAttribute('products_count', $brandCounts[$b->id])),
            'types' => collect(ProductType::cases())->filter(fn ($t) => isset($typeCounts[$t->value]))
                ->mapWithKeys(fn ($t) => [$t->value => ['label' => $t->label(), 'count' => $typeCounts[$t->value]]]),
        ];
    }

    /**
     * Sellable quantity per active branch (on hand minus reserved), for the given products.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, Collection<int, int>> product_id => [branch_id => qty]
     */
    public function stock(array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return Inventory::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('branch_id', Branch::where('is_active', true)->select('id'))
            ->selectRaw('product_id, branch_id, (quantity - reserved_quantity) as available')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('available', 'branch_id')->map(fn ($v) => max(0, (int) $v)));
    }

    public function isAvailable(Product $product, Collection $stock): bool
    {
        return ! $product->track_stock || ($stock[$product->id] ?? collect())->sum() > 0;
    }

    private function inStockQuery(): Builder
    {
        return Inventory::query()->select('product_id')
            ->whereIn('branch_id', Branch::where('is_active', true)->select('id'))
            ->groupBy('product_id')
            ->havingRaw('SUM(quantity - reserved_quantity) > 0');
    }
}
