<?php

namespace App\Modules\HR\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Services\HrService;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Sales\Models\Sale;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Employee performance: sales (via the employee's linked user), attendance, leave and the latest review rating. */
#[Title('Employee performance')]
class PerformanceReport extends Component
{
    use ReportFilters;

    public function mount(): void
    {
        $this->authorize('hr.view');
        $this->initFilters();
    }

    public function render(HrService $hr)
    {
        $branchIds = $this->branchIds();
        [$from, $to] = [$this->fromDate(), $this->toDate()];
        $employees = Employee::with('branch:id,code')->whereIn('branch_id', $branchIds)->where('is_active', true)->orderBy('name')->get();
        $sales = Sale::where('status', 'completed')->whereIn('cashier_id', $employees->pluck('user_id')->filter()->all())->whereIn('branch_id', $branchIds)
            ->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('cashier_id, COUNT(*) as n, SUM(total) as total')->groupBy('cashier_id')->get()->keyBy('cashier_id');
        $ratings = PerformanceReview::whereIn('employee_id', $employees->pluck('id'))->orderBy('period_end')->orderBy('id')->get()->keyBy('employee_id');

        $rows = $employees->map(function (Employee $e) use ($hr, $from, $to, $sales, $ratings) {
            $s = $e->user_id ? $sales->get($e->user_id) : null;
            $n = (int) ($s->n ?? 0);
            $total = (int) ($s->total ?? 0);

            return ['employee' => $e, 'sales_count' => $n, 'sales_total' => $total, 'avg' => $n ? intdiv($total, $n) : 0,
                'att' => $hr->summary($e, $from, $to), 'rating' => $ratings->get($e->id)?->rating];
        });

        return view('livewire.hr.performance', ['rows' => $rows, 'showMoney' => auth()->user()->can('reports.financial')] + $this->filterData());
    }
}
