<?php

namespace App\Modules\Reports\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Reports\Services\ReportEngine;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One screen for every operational report, with optional comparison of two date ranges
 * and CSV / Excel / PDF / print export.
 */
#[Title('Report center')]
class ReportCenter extends Component
{
    use ReportFilters;

    #[Url]
    public string $report = 'sales_period';

    #[Url]
    public string $group = 'day';

    #[Url]
    public bool $compare = false;

    #[Url]
    public string $fromB = '';

    #[Url]
    public string $toB = '';

    public function mount(): void
    {
        $this->authorize('reports.advanced');
        $this->initFilters();
        if (! isset(ReportEngine::REPORTS[$this->report])) {
            $this->report = 'sales_period';
        }
        $this->fromB = $this->validDate($this->fromB) ?? now()->parse($this->fromDate())->subYear()->toDateString();
        $this->toB = $this->validDate($this->toB) ?? now()->parse($this->toDate())->subYear()->toDateString();
    }

    public function params(): array
    {
        return ['report' => $this->report, 'group' => $this->group, 'branch' => $this->branch, 'from' => $this->fromDate(), 'to' => $this->toDate(),
            'compare' => $this->compare ? 1 : 0, 'fromB' => $this->fromB, 'toB' => $this->toB];
    }

    public function render(ReportEngine $engine)
    {
        $report = isset(ReportEngine::REPORTS[$this->report]) ? $this->report : 'sales_period';
        $ids = $this->branchIds();
        $comparable = isset(ReportEngine::COMPARABLE[$report]);
        $options = ['group' => in_array($this->group, ['day', 'week', 'month', 'year'], true) ? $this->group : 'day'];

        $data = $this->compare && $comparable
            ? $engine->compare($report, $ids, [$this->fromB ?: $this->fromDate(), $this->toB ?: $this->toDate()], [$this->fromDate(), $this->toDate()], $options)
            : $engine->run($report, $ids, $this->fromDate(), $this->toDate(), $options);

        return view('livewire.reports.center', [
            'data' => $data,
            'reports' => ReportEngine::REPORTS,
            'comparable' => $comparable,
            'exportParams' => $this->params(),
        ] + $this->filterData());
    }
}
