<?php

namespace App\Modules\Website\Livewire;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Website\Services\WebsiteService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Website products')]
class WebsiteProducts extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $show = '';   // '' | published | hidden | featured

    #[Url]
    public string $category = '';

    #[Url]
    public string $brand = '';

    /** @var list<int> */
    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('website.manage');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'show', 'category', 'brand'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function togglePublished(int $id, WebsiteService $service): void
    {
        $p = Product::findOrFail($id);
        $service->setPublished([$id], ! $p->show_on_website);
    }

    public function toggleFeatured(int $id, WebsiteService $service): void
    {
        $p = Product::findOrFail($id);
        $service->setFeatured([$id], ! $p->website_featured);
    }

    public function bulk(string $action, WebsiteService $service): void
    {
        $ids = array_map('intval', $this->selected);
        $n = match ($action) {
            'publish' => $service->setPublished($ids, true),
            'unpublish' => $service->setPublished($ids, false),
            'feature' => $service->setFeatured($ids, true),
            default => 0,
        };
        $this->selected = [];
        $this->dispatch('toast', message: __(':count products updated.', ['count' => $n]));
    }

    /** Publish every active product that currently has stock (one click to fill the site). */
    public function publishInStock(WebsiteService $service): void
    {
        $ids = Product::active()->where('show_on_website', false)->where('product_type', '!=', ProductType::Service->value)
            ->whereIn('id', Inventory::select('product_id')->groupBy('product_id')->havingRaw('SUM(quantity - reserved_quantity) > 0'))
            ->pluck('id')->all();
        $n = $service->setPublished($ids, true);
        $this->dispatch('toast', message: __(':count products updated.', ['count' => $n]));
    }

    public function render()
    {
        $q = Product::query()->active()->where('product_type', '!=', ProductType::Service->value)
            ->with(['primaryImage', 'brand:id,name,name_en', 'category:id,name,name_en'])
            ->withSum('inventory as stock', 'quantity')
            ->search($this->search);
        match ($this->show) {
            'published' => $q->where('show_on_website', true),
            'hidden' => $q->where('show_on_website', false),
            'featured' => $q->where('website_featured', true),
            default => null,
        };
        if ($this->category !== '') {
            $q->where('category_id', (int) $this->category);
        }
        if ($this->brand !== '') {
            $q->where('brand_id', (int) $this->brand);
        }

        return view('livewire.website.products', [
            'products' => $q->orderByDesc('show_on_website')->orderByDesc('id')->paginate(25),
            'publishedCount' => Product::active()->where('show_on_website', true)->count(),
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
        ]);
    }
}
