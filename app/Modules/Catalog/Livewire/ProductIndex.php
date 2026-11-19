<?php

namespace App\Modules\Catalog\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Products')]
class ProductIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $brand = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = 'active';

    /** @var list<int|string> */
    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('products.view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'category', 'brand', 'type', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function toggle(int $id, ProductService $service): void
    {
        $this->authorize('products.toggle');
        $product = Product::findOrFail($id);
        $service->setActive($product, ! $product->is_active);
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function printLabels()
    {
        $this->authorize('products.print-labels');
        $ids = array_values(array_unique(array_map('intval', $this->selected)));
        if ($ids === []) {
            $this->dispatch('toast', message: __('Select at least one product.'), type: 'error');

            return null;
        }

        return redirect()->route('products.labels', ['ids' => implode(',', array_slice($ids, 0, 200))]);
    }

    public function render(BranchAccess $access)
    {
        $allowed = $access->allowedIds();
        $current = $access->current();
        $stockBranches = $current > 0 ? [$current] : $allowed;

        $products = Product::query()
            ->with(['category:id,name,name_en', 'brand:id,name,name_en', 'primaryImage'])
            ->addSelect(['stock' => DB::table('inventory')->selectRaw('COALESCE(SUM(quantity),0)')
                ->whereColumn('inventory.product_id', 'products.id')
                ->whereIn('branch_id', $stockBranches)])
            ->search($this->search)
            ->when($this->category !== '', fn ($q) => $q->where('category_id', (int) $this->category))
            ->when($this->brand !== '', fn ($q) => $q->where('brand_id', (int) $this->brand))
            ->when($this->type !== '', fn ($q) => $q->where('product_type', $this->type))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.catalog.products.index', [
            'products' => $products,
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'types' => ProductType::options(),
        ]);
    }
}
