<?php

namespace App\Livewire\Concerns;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Collection;

/**
 * Shared product lookup for forms with line items (stock ops, transfers, purchases).
 */
trait SearchesProducts
{
    public string $productSearch = '';

    /** @return Collection<int, Product> */
    public function productResults(bool $activeOnly = true, bool $stockTrackedOnly = true): Collection
    {
        $term = trim($this->productSearch);
        if (mb_strlen($term) < 1) {
            return collect();
        }

        return Product::query()
            ->search($term)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->when($stockTrackedOnly, fn ($q) => $q->where('track_stock', true))
            ->orderByRaw('CASE WHEN sku = ? OR barcode = ? OR qr_code = ? THEN 0 ELSE 1 END', [$term, $term, $term])
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'name_en', 'sku', 'barcode', 'selling_price', 'purchase_price', 'cost_price']);
    }

    /**
     * Exact match on barcode / SKU / QR (scanner input). Returns null if none.
     */
    public function exactProduct(string $code, bool $stockTrackedOnly = true): ?Product
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return Product::query()
            ->where('is_active', true)
            ->when($stockTrackedOnly, fn ($q) => $q->where('track_stock', true))
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code)->orWhere('qr_code', $code))
            ->first();
    }
}
