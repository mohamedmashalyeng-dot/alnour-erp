<?php

namespace App\Modules\Reports\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Services\FinancialReports;
use App\Modules\Reports\Services\ReportEngine;
use App\Modules\Sales\Services\SalesReport;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Executive dashboard')]
class ExecutiveDashboard extends Component
{
    use ReportFilters;

    public function mount(): void
    {
        $this->authorize('reports.advanced');
        $this->initFilters();
    }

    public function render(FinancialReports $fin, SalesReport $sales, ReportEngine $engine)
    {
        $ids = $this->branchIds();
        $from = $this->fromDate();
        $to = $this->toDate();
        $f = $fin->dashboard($ids, $from, $to);
        $s = $sales->summary($ids, now()->parse($from)->startOfDay(), now()->parse($to)->endOfDay());
        $inventoryValue = (int) DB::table('inventory')->join('products', 'products.id', '=', 'inventory.product_id')
            ->whereIn('inventory.branch_id', $ids)->where('inventory.quantity', '>', 0)
            ->sum(DB::raw('inventory.quantity * products.cost_price'));

        return view('livewire.reports.executive', [
            'f' => $f, 's' => $s, 'inventoryValue' => $inventoryValue,
            'top' => $engine->top('sales_product', $ids, $from, $to, 'revenue', 10),
            'branchSales' => $engine->run('sales_branch', $ids, $from, $to)['rows'],
        ] + $this->filterData());
    }
}
