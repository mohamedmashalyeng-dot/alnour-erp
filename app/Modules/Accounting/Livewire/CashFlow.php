<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Services\FinancialReports;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Cash flow statement')]
class CashFlow extends Component
{
    use ReportFilters;

    public function mount(): void
    {
        $this->authorize('reports.statements');
        $this->initFilters();
    }

    public function render(FinancialReports $reports)
    {
        return view('livewire.accounting.cash-flow', [
            'cf' => $reports->cashFlow($this->branchIds(), $this->fromDate(), $this->toDate()),
        ] + $this->filterData());
    }
}
