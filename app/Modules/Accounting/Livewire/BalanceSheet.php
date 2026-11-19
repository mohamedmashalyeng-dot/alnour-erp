<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Services\FinancialReports;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Balance sheet')]
class BalanceSheet extends Component
{
    use ReportFilters;

    public function mount(): void
    {
        $this->authorize('reports.statements');
        $this->initFilters();
    }

    public function render(FinancialReports $reports)
    {
        return view('livewire.accounting.balance-sheet', [
            'bs' => $reports->balanceSheet($this->branchIds(), $this->toDate()),
            'showFrom' => false,
        ] + $this->filterData());
    }
}
