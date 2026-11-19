<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Services\AuditLogger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Monthly accounting periods (fiscal year = calendar year). Posting into a closed period is refused.
 */
class PeriodService
{
    public function __construct(private AuditLogger $audit) {}

    public function forDate(CarbonInterface|string $date): AccountingPeriod
    {
        $d = Carbon::parse($date);

        return AccountingPeriod::firstOrCreate(
            ['year' => $d->year, 'month' => $d->month],
            ['starts_on' => $d->copy()->startOfMonth()->toDateString(), 'ends_on' => $d->copy()->endOfMonth()->toDateString()],
        );
    }

    public function assertOpen(CarbonInterface|string $date): void
    {
        $period = $this->forDate($date);
        if ($period->isClosed()) {
            throw ValidationException::withMessages(['entry_date' => __('The accounting period :period is closed.', ['period' => sprintf('%04d-%02d', $period->year, $period->month)])]);
        }
    }

    public function close(int $year, int $month): AccountingPeriod
    {
        Gate::authorize('accounting.close-period');

        return DB::transaction(function () use ($year, $month) {
            $period = $this->forDate(Carbon::create($year, $month, 1));
            $period = AccountingPeriod::lockForUpdate()->findOrFail($period->id);
            if ($period->isClosed()) {
                return $period;
            }
            if (JournalEntry::where('status', 'draft')->whereBetween('entry_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])->exists()) {
                throw ValidationException::withMessages(['period' => __('Post or delete draft journal entries in this period before closing it.')]);
            }
            $period->status = 'closed';
            $period->closed_by = auth()->id();
            $period->closed_at = now();
            $period->save();
            $this->audit->log('accounting.period_closed', $period, [], ['period' => "$year-$month"]);

            return $period;
        });
    }

    public function reopen(int $year, int $month): AccountingPeriod
    {
        Gate::authorize('accounting.reopen-period');
        $period = AccountingPeriod::where(['year' => $year, 'month' => $month])->firstOrFail();
        if (JournalEntry::where('source_type', 'fiscal_year')->where('source_id', $year)->where('source_event', 'like', 'closing%')->where('status', 'posted')->exists()) {
            throw ValidationException::withMessages(['period' => __('This fiscal year is closed. Reopen the year first.')]);
        }
        $period->status = 'open';
        $period->closed_by = null;
        $period->closed_at = null;
        $period->save();
        $this->audit->log('accounting.period_reopened', $period, [], ['period' => "$year-$month"]);

        return $period;
    }

    /**
     * Year-end close: transfer every income-statement balance (per branch) into retained earnings
     * with a closing entry dated 31 Dec, then lock all 12 months.
     */
    public function closeYear(int $year, LedgerService $ledger, AccountResolver $accounts): JournalEntry
    {
        Gate::authorize('accounting.close-period');

        return DB::transaction(function () use ($year, $ledger) {
            $end = Carbon::create($year, 12, 31)->toDateString();
            $start = Carbon::create($year, 1, 1)->toDateString();

            $balances = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->whereIn('journal_entries.status', ['posted', 'reversed'])
                ->whereBetween('journal_entries.entry_date', [$start, $end])
                ->whereIn('accounts.type', ['revenue', 'cogs', 'expense'])
                ->groupBy('journal_lines.account_id', 'journal_lines.branch_id')
                ->selectRaw('journal_lines.account_id, journal_lines.branch_id, SUM(journal_lines.debit) as d, SUM(journal_lines.credit) as c')
                ->get();

            $lines = [];
            $perBranch = [];
            foreach ($balances as $b) {
                $net = (int) $b->d - (int) $b->c; // positive = debit balance
                if ($net === 0) {
                    continue;
                }
                $lines[] = ['account' => (int) $b->account_id, 'branch_id' => (int) $b->branch_id,
                    'debit' => $net < 0 ? -$net : 0, 'credit' => $net > 0 ? $net : 0, 'description' => __('Year-end closing')];
                $perBranch[$b->branch_id] = ($perBranch[$b->branch_id] ?? 0) + $net;
            }
            foreach ($perBranch as $branchId => $net) {
                if ($net !== 0) {
                    // Net debit (loss) reduces retained earnings; net credit (profit) increases it.
                    $lines[] = ['account' => 'retained.earnings', 'branch_id' => (int) $branchId,
                        'debit' => $net > 0 ? $net : 0, 'credit' => $net < 0 ? -$net : 0, 'description' => __('Net result :year', ['year' => $year])];
                }
            }

            $entry = $lines
                ? $ledger->post(['entry_date' => $end, 'source_type' => 'fiscal_year', 'source_id' => $year, 'event' => 'closing', 'repost' => true,
                    'memo' => __('Year-end closing :year', ['year' => $year])], $lines)
                : null;

            for ($m = 1; $m <= 12; $m++) {
                $p = $this->forDate(Carbon::create($year, $m, 1));
                $p->status = 'closed';
                $p->closed_by = auth()->id();
                $p->closed_at = now();
                $p->save();
            }
            $this->audit->log('accounting.year_closed', $entry, [], ['year' => $year]);

            return $entry ?? new JournalEntry;
        });
    }

    public function reopenYear(int $year, LedgerService $ledger): void
    {
        Gate::authorize('accounting.reopen-period');
        DB::transaction(function () use ($year, $ledger) {
            AccountingPeriod::where('year', $year)->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);
            $closing = JournalEntry::where('source_type', 'fiscal_year')->where('source_id', $year)->where('source_event', 'like', 'closing%')->where('status', 'posted')->first();
            if ($closing) {
                $ledger->reverse($closing, __('Fiscal year reopened'), Carbon::create($year, 12, 31)->toDateString());
            }
            $this->audit->log('accounting.year_reopened', $closing, [], ['year' => $year]);
        });
    }

    /** Ensure the retained-earnings account exists (used by tests/diagnostics). */
    public function retainedEarnings(AccountResolver $accounts): Account
    {
        return $accounts->account('retained.earnings');
    }
}
