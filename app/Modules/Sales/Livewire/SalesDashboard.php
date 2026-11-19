<?php

namespace App\Modules\Sales\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Sales\Services\SalesReport;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Sales dashboard')]
class SalesDashboard extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?int $branch = null;

    public function mount(BranchAccess $access): void
    {
        $this->authorize('reports.financial');
        $this->from = $this->from ?: today()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
        $this->branch ??= $access->current();
    }

    public function render(BranchAccess $access, SalesReport $report)
    {
        if ($this->branch) {
            $access->authorize($this->branch);
        } else {
            abort_unless(auth()->user()->canAccessAllBranches(), 403);
        }
        $ids = $this->branch ? [$this->branch] : $access->allowedIds();
        $parse = fn ($v, $fallback) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? Carbon::parse($v) : $fallback;
        $from = $parse($this->from, today()->startOfMonth())->startOfDay();
        $to = $parse($this->to, today())->endOfDay();

        return view('livewire.sales.dashboard', [
            'summary' => $report->summary($ids, $from, $to),
            'today' => $report->summary($ids, today()->startOfDay(), now()),
            'methods' => $report->byPaymentMethod($ids, $from, $to),
            'byBranch' => $report->byBranch($ids, $from, $to),
            'byCashier' => $report->byCashier($ids, $from, $to),
            'top' => $report->topProducts($ids, $from, $to),
            'branches' => $access->allowedBranches(activeOnly: false),
            'canAll' => auth()->user()->canAccessAllBranches(),
            'methodLabels' => PaymentMethod::options(),
        ]);
    }
}
