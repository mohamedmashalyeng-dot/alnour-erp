<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Services\FinancialReports;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Trial balance')]
class TrialBalance extends Component
{
    use ReportFilters;

    public function mount(): void
    {
        $this->authorize('reports.statements');
        $this->initFilters();
    }

    public function render(FinancialReports $reports)
    {
        return view('livewire.accounting.trial-balance', [
            'tb' => $reports->trialBalance($this->branchIds(), $this->toDate()),
            'showFrom' => false,
        ] + $this->filterData());
    }
}
