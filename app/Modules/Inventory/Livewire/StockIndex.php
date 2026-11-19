<?php

namespace App\Modules\Inventory\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stock levels per branch, or combined across all allowed branches (branch = 0).
 */
#[Title('Stock levels')]
class StockIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $branch = null;

    #[Url]
    public string $category = '';

    #[Url]
    public bool $lowOnly = false;

    public array $minLevels = [];

    public function mount(BranchAccess $access): void
    {
        $this->authorize('inventory.view');
        if ($this->branch === null) {
            $this->branch = $access->current();
        }
        $this->guardBranch($access);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'branch', 'category', 'lowOnly'], true)) {
            $this->resetPage();
        }
    }

    public function updatedBranch(BranchAccess $access): void
    {
        $this->guardBranch($access);
    }

    public function saveMinLevel(int $productId, InventoryService $inventory, BranchAccess $access): void
    {
        $this->guardBranch($access);
        if (! $this->branch) {
            return;
        }
        $level = $this->minLevels[$productId] ?? null;
        if (! is_numeric($level) || (int) $level < 0 || (int) $level > 1000000) {
            $this->addError('minLevels.'.$productId, __('Invalid value.'));

            return;
        }
        $inventory->setMinStockLevel($this->branch, $productId, (int) $level);
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    private function guardBranch(BranchAccess $access): void
    {
        if ($this->branch === BranchAccess::ALL) {
            abort_unless(auth()->user()->canAccessAllBranches(), 403);
        } else {
            $access->authorize((int) $this->branch);
        }
    }

    public function render(BranchAccess $access)
    {
        $this->guardBranch($access);
        $branchIds = $this->branch ? [$this->branch] : $access->allowedIds();

        $agg = DB::table('inventory')
            ->whereIn('branch_id', $branchIds)
            ->groupBy('product_id')
            ->select('product_id',
                DB::raw('SUM(quantity) as qty'),
                DB::raw('SUM(reserved_quantity) as reserved'),
                DB::raw('SUM(min_stock_level) as min_level'),
                DB::raw('SUM(CASE WHEN min_stock_level > 0 AND quantity <= min_stock_level THEN 1 ELSE 0 END) as low_rows'));

        $rows = Product::query()
            ->joinSub($agg, 'stock', 'stock.product_id', '=', 'products.id')
            ->with(['category:id,name,name_en', 'brand:id,name,name_en'])
            ->select('products.*', 'stock.qty', 'stock.reserved', 'stock.min_level', 'stock.low_rows')
            ->search($this->search)
            ->when($this->category !== '', fn ($q) => $q->where('products.category_id', (int) $this->category))
            ->when($this->lowOnly, fn ($q) => $q->where('stock.low_rows', '>', 0))
            ->orderBy('products.name')
            ->paginate(30);

        if ($this->branch) {
            foreach ($rows as $r) {
                $this->minLevels[$r->id] ??= (string) $r->min_level;
            }
        }

        $totals = DB::table('inventory')->whereIn('branch_id', $branchIds)
            ->selectRaw('COALESCE(SUM(quantity),0) as qty, COALESCE(SUM(reserved_quantity),0) as reserved, SUM(CASE WHEN min_stock_level > 0 AND quantity <= min_stock_level THEN 1 ELSE 0 END) as low')
            ->first();

        $valuation = null;
        if (auth()->user()->can('products.view-cost')) {
            $valuation = (int) DB::table('inventory')->join('products', 'products.id', '=', 'inventory.product_id')
                ->whereIn('inventory.branch_id', $branchIds)->where('inventory.quantity', '>', 0)
                ->sum(DB::raw('inventory.quantity * products.cost_price'));
        }

        return view('livewire.inventory.stock-index', [
            'rows' => $rows,
            'totals' => $totals,
            'valuation' => $valuation,
            'branches' => $access->allowedBranches(activeOnly: false),
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'canAll' => auth()->user()->canAccessAllBranches(),
        ]);
    }
}
