<?php

namespace App\Modules\Reports\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Services\FinancialReports;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Purchasing\Services\PurchaseService;
use App\Modules\Reports\Services\AiNarrator;
use App\Modules\Reports\Services\InsightsEngine;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Analytics + optional AI summary. Recommendations never change data on their own:
 * reorder suggestions can only be turned into DRAFT purchase orders by a user with
 * purchasing rights, and those drafts still need a human confirmation.
 */
#[Title('Business insights')]
class BusinessInsights extends Component
{
    use ReportFilters;

    /** @var list<string> "branch-product" keys selected for draft purchase orders */
    public array $selected = [];

    public ?string $aiText = null;

    public bool $aiOk = false;

    public function mount(): void
    {
        $this->authorize('ai.insights');
        $this->initFilters();
    }

    public function analyze(InsightsEngine $engine, FinancialReports $fin, AiNarrator $ai, AuditLogger $audit): void
    {
        $this->authorize('ai.insights');
        $ids = $this->branchIds();
        $insights = $engine->all($ids, $this->toDate());
        $p = $fin->incomeStatement($ids, now()->parse($this->toDate())->subDays(30)->toDateString(), $this->toDate());
        $headline = ['last_30_days' => array_intersect_key($p, array_flip(['total_revenue', 'gross_profit', 'total_expenses', 'net_profit']))];
        $result = $ai->summarize($insights, $headline, $this->branch ? 'branch '.$this->branch : 'all authorized branches');
        $this->aiText = $result['text'];
        $this->aiOk = $result['ok'];
        $audit->log('ai.summary_requested', null, [], ['branches' => $ids, 'ok' => $result['ok']]);
    }

    public function createDrafts(InsightsEngine $engine, PurchaseService $purchases): void
    {
        $this->authorize('purchases.create');
        $suggestions = collect($engine->reorderSuggestions($this->branchIds(), now()->parse($this->toDate())->endOfDay()))
            ->keyBy(fn ($s) => $s['branch_id'].'-'.$s['product_id'])
            ->only($this->selected);

        $skipped = $suggestions->whereNull('supplier_id')->count();
        $created = [];
        foreach ($suggestions->whereNotNull('supplier_id')->groupBy(fn ($s) => $s['branch_id'].'-'.$s['supplier_id']) as $group) {
            $first = $group->first();
            $po = $purchases->saveDraft([
                'supplier_id' => (int) $first['supplier_id'],
                'branch_id' => (int) $first['branch_id'],
                'order_date' => today()->toDateString(),
                'notes' => __('Draft created from reorder suggestions. Review quantities and prices before confirming.'),
                'lines' => $group->map(fn ($s) => ['product_id' => (int) $s['product_id'], 'quantity' => (int) $s['suggested'], 'unit_cost' => (int) $s['unit_cost']])->values()->all(),
            ]);
            $created[] = $po->number;
        }
        $this->selected = [];
        $msg = $created ? __('Draft purchase orders created: :list', ['list' => implode(', ', $created)]) : __('Nothing was created.');
        if ($skipped) {
            $msg .= ' '.__(':n item(s) skipped because the product has no default supplier.', ['n' => $skipped]);
        }
        session()->flash('success', $msg);
    }

    public function render(InsightsEngine $engine, AiNarrator $ai)
    {
        return view('livewire.reports.insights', [
            'i' => $engine->all($this->branchIds(), $this->toDate()),
            'aiEnabled' => $ai->enabled(),
            'showFrom' => false,
        ] + $this->filterData());
    }
}
