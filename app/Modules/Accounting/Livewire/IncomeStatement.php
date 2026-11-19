<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Services\FinancialReports;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Income statement')]
class IncomeStatement extends Component
{
    use ReportFilters;

    /** period | monthly | annual */
    #[Url]
    public string $mode = 'period';

    public function mount(): void
    {
        $this->authorize('reports.statements');
        $this->initFilters();
    }

    public function render(FinancialReports $reports)
    {
        $ids = $this->branchIds();
        $data = ['mode' => $this->mode];
        $year = (int) Carbon::parse($this->toDate())->format('Y');

        if ($this->mode === 'monthly') {
            $data['months'] = $reports->monthlyComparison($ids, $year);
            $data['year'] = $year;
        } elseif ($this->mode === 'annual') {
            $data['years'] = [
                $year - 1 => $reports->incomeStatement($ids, ($year - 1).'-01-01', ($year - 1).'-12-31'),
                $year => $reports->incomeStatement($ids, $year.'-01-01', $year.'-12-31'),
            ];
        } else {
            $data['pl'] = $reports->incomeStatement($ids, $this->fromDate(), $this->toDate());
        }

        return view('livewire.accounting.income-statement', $data + $this->filterData());
    }
}
