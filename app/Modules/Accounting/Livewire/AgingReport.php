<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Services\FinancialReports;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Receivables / payables aging with overdue highlighting. */
#[Title('Aging report')]
class AgingReport extends Component
{
    use ReportFilters;

    #[Url]
    public string $type = 'receivables';

    #[Url]
    public bool $overdueOnly = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->canany(['reports.statements', 'reports.financial']), 403);
        $this->initFilters();
        $this->type = $this->type === 'payables' ? 'payables' : 'receivables';
    }

    public function render(FinancialReports $reports)
    {
        $aging = $reports->aging($this->type === 'payables' ? 'payables' : 'receivables', $this->branchIds(), $this->toDate());
        if ($this->overdueOnly) {
            $aging['rows'] = $aging['rows']->where('overdue', true)->values();
        }

        return view('livewire.accounting.aging', ['aging' => $aging, 'showFrom' => false] + $this->filterData());
    }
}
