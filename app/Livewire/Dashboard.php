<?php

namespace App\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Sales\Services\SalesReport;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function mount(): void
    {
        $this->authorize('dashboard.view');
    }

    public function render(BranchAccess $branches)
    {
        $current = $branches->current();
        $ids = $current > 0 ? [$current] : $branches->allowedIds();
        $user = auth()->user();

        $stats = [
            'products' => Product::where('is_active', true)->count(),
            'units' => (int) Inventory::whereIn('branch_id', $ids)->sum('quantity'),
            'low' => Inventory::whereIn('branch_id', $ids)->lowStock()->count(),
            'transfers' => StockTransfer::whereIn('status', [TransferStatus::Pending->value, TransferStatus::Approved->value, TransferStatus::Dispatched->value])
                ->where(fn ($q) => $q->whereIn('from_branch_id', $ids)->orWhereIn('to_branch_id', $ids))->count(),
        ];

        $sales = null;
        if ($user->can('reports.financial')) {
            $sales = app(SalesReport::class)->summary($ids, today()->startOfDay(), now());
        }

        $byBranch = Inventory::query()
            ->join('branches', 'branches.id', '=', 'inventory.branch_id')
            ->whereIn('inventory.branch_id', $ids)
            ->groupBy('branches.id', 'branches.name', 'branches.name_en')
            ->select('branches.id', 'branches.name', 'branches.name_en',
                DB::raw('SUM(inventory.quantity) as units'),
                DB::raw('SUM(CASE WHEN inventory.min_stock_level > 0 AND inventory.quantity <= inventory.min_stock_level THEN 1 ELSE 0 END) as low'))
            ->get();

        $lowStock = Inventory::with(['product:id,name,name_en,sku', 'branch:id,name,name_en,code'])
            ->whereIn('branch_id', $ids)->lowStock()
            ->orderBy('quantity')->limit(8)->get();

        $recent = $user->can('inventory.view-movements')
            ? InventoryTransaction::with(['product:id,name,name_en,sku', 'branch:id,name,name_en,code', 'user:id,name'])
                ->whereIn('branch_id', $ids)->latest('id')->limit(8)->get()
            : collect();

        return view('livewire.dashboard', compact('stats', 'byBranch', 'lowStock', 'recent', 'sales'));
    }
}
