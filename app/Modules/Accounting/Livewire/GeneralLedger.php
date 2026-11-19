<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\FinancialReports;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** General ledger / account statement for one account. */
#[Title('General ledger')]
class GeneralLedger extends Component
{
    use ReportFilters;

    #[Url]
    public ?int $account = null;

    public function mount(): void
    {
        $this->authorize('reports.statements');
        $this->initFilters();
    }

    public function render(FinancialReports $reports)
    {
        $ids = $this->branchIds();
        $accounts = Account::where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'name_en']);
        $gl = $this->account && Account::whereKey($this->account)->exists()
            ? $reports->generalLedger((int) $this->account, $ids, $this->fromDate(), $this->toDate())
            : null;

        return view('livewire.accounting.general-ledger', ['accounts' => $accounts, 'gl' => $gl] + $this->filterData());
    }
}
