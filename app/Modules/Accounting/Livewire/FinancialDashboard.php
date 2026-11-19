<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Services\FinancialReports;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Financial dashboard')]
class FinancialDashboard extends Component
{
    use ReportFilters;

    public function mount(): void
    {
        $this->authorize('accounting.view');
        $this->initFilters();
    }

    public function render(FinancialReports $reports)
    {
        return view('livewire.accounting.dashboard', [
            'd' => $reports->dashboard($this->branchIds(), $this->fromDate(), $this->toDate()),
        ] + $this->filterData());
    }
}
