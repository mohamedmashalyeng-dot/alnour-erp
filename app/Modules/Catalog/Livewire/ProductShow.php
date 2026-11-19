<?php

namespace App\Modules\Catalog\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CodeGenerator;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use Livewire\Component;

class ProductShow extends Component
{
    public Product $product;

    public function mount(Product $product): void
    {
        $this->authorize('products.view');
        $this->product = $product;
    }

    public function render(BranchAccess $access, CodeGenerator $codes)
    {
        $product = $this->product->load(['category', 'brand', 'supplier', 'images', 'taxRate']);
        $allowed = $access->allowedIds();

        $stock = Inventory::with('branch:id,code,name,name_en')
            ->where('product_id', $product->id)
            ->whereIn('branch_id', $allowed)
            ->get();

        $movements = auth()->user()->can('inventory.view-movements')
            ? InventoryTransaction::with(['product:id,name,name_en,sku', 'branch:id,name,name_en,code', 'user:id,name'])
                ->where('product_id', $product->id)->whereIn('branch_id', $allowed)
                ->latest('id')->limit(15)->get()
            : collect();

        return view('livewire.catalog.products.show', [
            'stock' => $stock,
            'movements' => $movements,
            'barcodeSvg' => $codes->barcodeSvg($product->barcode),
            'qrSvg' => $codes->qrSvg($codes->qrPayload($product)),
        ])->title($product->displayName());
    }
}
