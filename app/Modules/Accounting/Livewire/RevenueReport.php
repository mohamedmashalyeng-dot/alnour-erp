<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Services\FinancialReports;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Daily / monthly / annual revenue from posted revenue accounts (not from raw sales totals). */
#[Title('Revenue report')]
class RevenueReport extends Component
{
    use ReportFilters;

    #[Url]
    public string $group = 'day';

    public function mount(): void
    {
        $this->authorize('reports.statements');
        $this->initFilters('year');
    }

    public function render(FinancialReports $reports)
    {
        $group = in_array($this->group, ['day', 'month', 'year'], true) ? $this->group : 'day';

        return view('livewire.accounting.revenue', [
            'rows' => $reports->revenue($this->branchIds(), $this->fromDate(), $this->toDate(), $group),
        ] + $this->filterData());
    }
}
