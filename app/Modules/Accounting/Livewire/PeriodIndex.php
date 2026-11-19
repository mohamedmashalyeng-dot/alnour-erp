<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\LedgerService;
use App\Modules\Accounting\Services\PeriodService;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Accounting periods')]
class PeriodIndex extends Component
{
    #[Url]
    public int $year = 0;

    public function mount(): void
    {
        $this->authorize('accounting.view');
        $this->year = $this->year ?: (int) today()->format('Y');
    }

    public function close(int $month, PeriodService $periods): void
    {
        $periods->close($this->year, $month);
        $this->dispatch('toast', message: __('Period closed.'));
    }

    public function reopen(int $month, PeriodService $periods): void
    {
        $periods->reopen($this->year, $month);
        $this->dispatch('toast', message: __('Period reopened.'));
    }

    public function closeYear(PeriodService $periods, LedgerService $ledger, AccountResolver $accounts): void
    {
        abort_if($this->year >= (int) today()->format('Y'), 422, __('Only past fiscal years can be closed.'));
        $periods->closeYear($this->year, $ledger, $accounts);
        $this->dispatch('toast', message: __('Fiscal year closed.'));
    }

    public function reopenYear(PeriodService $periods, LedgerService $ledger): void
    {
        $periods->reopenYear($this->year, $ledger);
        $this->dispatch('toast', message: __('Fiscal year reopened.'));
    }

    public function render(PeriodService $periods)
    {
        $months = collect(range(1, 12))->map(function ($m) {
            $p = AccountingPeriod::with('closer:id,name')->where(['year' => $this->year, 'month' => $m])->first();
            $start = Carbon::create($this->year, $m, 1);

            return (object) [
                'month' => $m,
                'label' => $start->translatedFormat('F Y'),
                'status' => $p?->status ?? 'open',
                'closed_at' => $p?->closed_at,
                'closer' => $p?->closer?->name,
                'entries' => JournalEntry::whereBetween('entry_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])->count(),
                'future' => $start->isAfter(today()),
            ];
        });

        return view('livewire.accounting.periods', [
            'months' => $months,
            'yearClosed' => JournalEntry::where('source_type', 'fiscal_year')->where('source_id', $this->year)->where('status', 'posted')->exists(),
        ]);
    }
}
