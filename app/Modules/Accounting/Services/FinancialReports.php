<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Core\Services\Settings;
use App\Modules\Customers\Services\CustomerLedger;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\CashRegister;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * All financial statements are derived from POSTED journal lines (reversed entries and their
 * reversals both stay in the ledger and cancel out). Amounts are integer minor units.
 * Every method takes the list of branch ids the caller is allowed to see.
 */
class FinancialReports
{
    public function __construct(private AccountResolver $accounts, private Settings $settings) {}

    /** Base query over posted lines in the given branches and date range. */
    private function lines(array $branchIds, ?string $from = null, ?string $to = null): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->whereIn('journal_lines.branch_id', $branchIds ?: [0])
            ->when($from, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('journal_entries.entry_date', '<=', $to));
    }

    /**
     * Per-account debit/credit totals.
     *
     * @return Collection<int, object{id:int, code:string, name:string, name_en:?string, type:string, normal_balance:string, debit:int, credit:int, balance:int}>
     */
    public function accountTotals(array $branchIds, ?string $from = null, ?string $to = null, ?array $types = null): Collection
    {
        return $this->lines($branchIds, $from, $to)
            ->when($types, fn ($q) => $q->whereIn('accounts.type', $types))
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.name_en', 'accounts.type', 'accounts.normal_balance')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.name_en, accounts.type, accounts.normal_balance,
                SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get()
            ->map(function ($r) {
                $r->debit = (int) $r->debit;
                $r->credit = (int) $r->credit;
                // Natural-sign balance: positive = balance on the account's normal side.
                $r->balance = $r->normal_balance === 'debit' ? $r->debit - $r->credit : $r->credit - $r->debit;

                return $r;
            });
    }

    /** Trial balance as of a date: each account shown in its debit or credit column. Totals always match. */
    public function trialBalance(array $branchIds, string $asOf, ?string $from = null): array
    {
        $rows = $this->accountTotals($branchIds, $from, $asOf)->map(function ($r) {
            $net = $r->debit - $r->credit;
            $r->dr = max(0, $net);
            $r->cr = max(0, -$net);

            return $r;
        })->filter(fn ($r) => $r->dr !== 0 || $r->cr !== 0)->values();

        return ['rows' => $rows, 'debit' => $rows->sum('dr'), 'credit' => $rows->sum('cr')];
    }

    /** Account statement / general ledger for one account with running balance. */
    public function generalLedger(int $accountId, array $branchIds, string $from, string $to): array
    {
        $account = Account::findOrFail($accountId);
        $sign = $account->normal_balance === 'debit' ? 1 : -1;
        $opening = (int) $this->lines($branchIds, null, Carbon::parse($from)->subDay()->toDateString())
            ->where('journal_lines.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) - COALESCE(SUM(journal_lines.credit),0) as b')->value('b') * $sign;

        $rows = $this->lines($branchIds, $from, $to)->where('journal_lines.account_id', $accountId)
            ->leftJoin('branches', 'branches.id', '=', 'journal_lines.branch_id')
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.id')
            ->select('journal_entries.id as entry_id', 'journal_entries.number', 'journal_entries.entry_date', 'journal_entries.memo',
                'journal_lines.debit', 'journal_lines.credit', 'journal_lines.description', 'branches.code as branch_code')
            ->get();

        $balance = $opening;
        foreach ($rows as $r) {
            $balance += ((int) $r->debit - (int) $r->credit) * $sign;
            $r->balance = $balance;
        }

        return ['account' => $account, 'opening' => $opening, 'rows' => $rows, 'closing' => $balance];
    }

    /** Income statement (profit & loss) for a period. */
    public function incomeStatement(array $branchIds, string $from, string $to): array
    {
        $rows = $this->accountTotals($branchIds, $from, $to, ['revenue', 'cogs', 'expense']);
        // Exclude the year-end closing entry, otherwise a closed year would show zero.
        $closing = $this->closingTotals($branchIds, $from, $to);
        $rows = $rows->map(function ($r) use ($closing) {
            if (isset($closing[$r->id])) {
                $r->debit -= $closing[$r->id]['d'];
                $r->credit -= $closing[$r->id]['c'];
                $r->balance = $r->normal_balance === 'debit' ? $r->debit - $r->credit : $r->credit - $r->debit;
            }

            return $r;
        })->filter(fn ($r) => $r->balance !== 0)->values();

        $revenue = $rows->where('type', 'revenue');
        $cogs = $rows->where('type', 'cogs');
        $expenses = $rows->where('type', 'expense');
        $totalRevenue = (int) $revenue->sum('balance');
        $totalCogs = (int) $cogs->sum('balance');
        $totalExpenses = (int) $expenses->sum('balance');

        return [
            'revenue' => $revenue->values(), 'cogs' => $cogs->values(), 'expenses' => $expenses->values(),
            'total_revenue' => $totalRevenue,
            'total_cogs' => $totalCogs,
            'gross_profit' => $totalRevenue - $totalCogs,
            'total_expenses' => $totalExpenses,
            'net_profit' => $totalRevenue - $totalCogs - $totalExpenses,
        ];
    }

    /** P&L per month of a year (for monthly comparisons). */
    public function monthlyComparison(array $branchIds, int $year): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $start = Carbon::create($year, $m, 1);
            $p = $this->incomeStatement($branchIds, $start->toDateString(), $start->copy()->endOfMonth()->toDateString());
            $months[$m] = array_intersect_key($p, array_flip(['total_revenue', 'total_cogs', 'gross_profit', 'total_expenses', 'net_profit']));
        }

        return $months;
    }

    /** Balance sheet as of a date. Assets = Liabilities + Equity (+ unclosed current earnings). */
    public function balanceSheet(array $branchIds, string $asOf): array
    {
        $rows = $this->accountTotals($branchIds, null, $asOf, ['asset', 'liability', 'equity'])->filter(fn ($r) => $r->balance !== 0);
        $pl = $this->accountTotals($branchIds, null, $asOf, ['revenue', 'cogs', 'expense']);
        $earnings = (int) $pl->sum(fn ($r) => $r->credit - $r->debit); // all-time P&L not yet closed to retained earnings

        $assets = $rows->where('type', 'asset')->values();
        $liabilities = $rows->where('type', 'liability')->values();
        $equity = $rows->where('type', 'equity')->values();
        $totalEquity = (int) $equity->sum('balance') + $earnings;

        return [
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'current_earnings' => $earnings,
            'total_assets' => (int) $assets->sum('balance'),
            'total_liabilities' => (int) $liabilities->sum('balance'),
            'total_equity' => $totalEquity,
            'balanced' => (int) $assets->sum('balance') === (int) $liabilities->sum('balance') + $totalEquity,
        ];
    }

    /**
     * Cash flow statement (direct method) from movements of cash & bank & card-clearing accounts,
     * classified by the document that caused them. Transfers between cash accounts net to zero.
     */
    public function cashFlow(array $branchIds, string $from, string $to): array
    {
        $cashAccounts = $this->cashAccountIds();
        $opening = $this->sumAccounts($cashAccounts, $branchIds, null, Carbon::parse($from)->subDay()->toDateString());

        $movements = $this->lines($branchIds, $from, $to)
            ->whereIn('journal_lines.account_id', $cashAccounts)
            ->leftJoin('payments', fn ($j) => $j->on('payments.id', '=', 'journal_entries.source_id')->where('journal_entries.source_type', '=', 'payment'))
            ->leftJoin('treasury_movements', fn ($j) => $j->on('treasury_movements.id', '=', 'journal_entries.source_id')->where('journal_entries.source_type', '=', 'treasury_movement'))
            ->leftJoin('accounts as counter', 'counter.id', '=', 'treasury_movements.counter_account_id')
            ->groupBy('journal_entries.source_type', 'payments.party_type', 'payments.direction', 'treasury_movements.type', 'counter.type', 'counter.code')
            ->selectRaw('journal_entries.source_type, payments.party_type, payments.direction, treasury_movements.type as tm_type, counter.type as counter_type, counter.code as counter_code,
                SUM(journal_lines.debit) - SUM(journal_lines.credit) as net')
            ->get();

        $sections = ['operating' => [], 'investing' => [], 'financing' => []];
        foreach ($movements as $m) {
            $net = (int) $m->net;
            [$section, $label] = match (true) {
                $m->source_type === 'payment' && $m->party_type === 'customer' && $m->direction === 'in' => ['operating', 'Receipts from customers'],
                $m->source_type === 'payment' && $m->party_type === 'customer' => ['operating', 'Refunds to customers'],
                $m->source_type === 'payment' && $m->party_type === 'supplier' => ['operating', 'Payments to suppliers'],
                $m->source_type === 'expense' => ['operating', 'Operating expenses paid'],
                in_array($m->source_type, ['payroll_run', 'employee_advance'], true) => ['operating', 'Salaries and advances paid'],
                $m->source_type === 'treasury_movement' && $m->tm_type === 'transfer' => ['operating', 'Transfers between cash and bank'],
                $m->source_type === 'treasury_movement' && $m->counter_type === 'equity' => ['financing', 'Owner capital / drawings'],
                $m->source_type === 'treasury_movement' && str_starts_with((string) $m->counter_code, '12') => ['investing', 'Purchase / sale of fixed assets'],
                default => ['operating', 'Other operating cash flows'],
            };
            $sections[$section][$label] = ($sections[$section][$label] ?? 0) + $net;
        }
        $totals = array_map(fn ($s) => array_sum($s), $sections);
        $netChange = array_sum($totals);

        return ['opening' => $opening, 'sections' => $sections, 'totals' => $totals, 'net_change' => $netChange, 'closing' => $opening + $netChange];
    }

    /** VAT report: output tax (sales) minus recoverable input tax (purchases, expenses). */
    public function vatReport(array $branchIds, string $from, string $to): array
    {
        $out = $this->lines($branchIds, $from, $to)->where('journal_lines.account_id', $this->accounts->id('vat.output'))
            ->selectRaw('COALESCE(SUM(journal_lines.credit),0) - COALESCE(SUM(journal_lines.debit),0) as v')->value('v');
        $in = $this->lines($branchIds, $from, $to)->where('journal_lines.account_id', $this->accounts->id('vat.input'))
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) - COALESCE(SUM(journal_lines.credit),0) as v')->value('v');

        $invoices = Sale::query()->whereIn('branch_id', $branchIds)->whereIn('status', CustomerLedger::OPEN_STATUSES)
            ->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59'])->where('tax_total', '>', 0)
            ->orderBy('sold_at')->limit(500)->get(['id', 'invoice_number', 'sold_at', 'total', 'tax_total', 'returned_amount']);

        return ['output' => (int) $out, 'input' => (int) $in, 'net' => (int) $out - (int) $in, 'invoices' => $invoices];
    }

    /** Revenue (sales + other income, net of returns) grouped by day / month / year. */
    public function revenue(array $branchIds, string $from, string $to, string $groupBy = 'day'): Collection
    {
        $driver = DB::connection()->getDriverName();
        $expr = match ($groupBy) {
            'year' => $driver === 'sqlite' ? "strftime('%Y', journal_entries.entry_date)" : 'YEAR(journal_entries.entry_date)',
            'month' => $driver === 'sqlite' ? "strftime('%Y-%m', journal_entries.entry_date)" : "DATE_FORMAT(journal_entries.entry_date, '%Y-%m')",
            default => $driver === 'sqlite' ? 'date(journal_entries.entry_date)' : 'DATE(journal_entries.entry_date)',
        };

        return $this->lines($branchIds, $from, $to)->where('accounts.type', 'revenue')
            ->whereNot(fn ($q) => $q->where('journal_entries.source_type', 'fiscal_year'))
            ->groupByRaw($expr)->orderByRaw($expr)
            ->selectRaw("$expr as period,
                SUM(CASE WHEN accounts.code LIKE '41%' THEN journal_lines.credit - journal_lines.debit ELSE 0 END) as sales,
                SUM(CASE WHEN accounts.code NOT LIKE '41%' THEN journal_lines.credit - journal_lines.debit ELSE 0 END) as other")
            ->get()->map(fn ($r) => (object) ['period' => (string) $r->period, 'sales' => (int) $r->sales, 'other' => (int) $r->other, 'total' => (int) $r->sales + (int) $r->other]);
    }

    /** P&L per branch for the period. */
    public function branchPerformance(array $branchIds, string $from, string $to): Collection
    {
        return DB::table('branches')->whereIn('id', $branchIds)->orderBy('code')->get(['id', 'code', 'name', 'name_en'])
            ->map(function ($b) use ($from, $to) {
                $p = $this->incomeStatement([$b->id], $from, $to);

                return (object) ['branch' => $b, 'revenue' => $p['total_revenue'], 'cogs' => $p['total_cogs'], 'gross' => $p['gross_profit'],
                    'expenses' => $p['total_expenses'], 'net' => $p['net_profit']];
            });
    }

    /**
     * Receivables or payables aging by document age.
     *
     * @return array{rows: Collection, buckets: array<string,int>, overdue_days:int}
     */
    public function aging(string $type, array $branchIds, string $asOf): array
    {
        $buckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        $as = Carbon::parse($asOf)->endOfDay();
        if ($type === 'payables') {
            $docs = PurchaseOrder::with('supplier:id,name')->whereIn('branch_id', $branchIds)->where('status', PurchaseStatus::Received->value)
                ->whereColumn('paid_amount', '<', 'total')->where('received_at', '<=', $as)->get()
                ->map(fn ($po) => (object) ['id' => $po->id, 'number' => $po->number, 'party' => $po->supplier->name, 'date' => $po->received_at,
                    'total' => $po->total, 'due' => $po->balanceDue(), 'route' => route('purchases.show', $po)]);
        } else {
            $docs = Sale::with('customer:id,name')->whereIn('branch_id', $branchIds)->whereIn('status', CustomerLedger::OPEN_STATUSES)
                ->whereRaw('total - returned_amount - paid_amount + refunded_amount > 0')->where('sold_at', '<=', $as)->get()
                ->map(fn ($s) => (object) ['id' => $s->id, 'number' => $s->invoice_number, 'party' => $s->customer?->name ?? __('Walk-in customer'), 'date' => $s->sold_at,
                    'total' => $s->total, 'due' => $s->balanceDue(), 'route' => route('sales.show', $s)]);
        }
        $terms = (int) $this->settings->get('payment_terms_days', 30);
        $docs = $docs->map(function ($d) use (&$buckets, $as, $terms) {
            $d->age = (int) $d->date->diffInDays($as);
            $d->bucket = match (true) {
                $d->age <= 30 => '0-30', $d->age <= 60 => '31-60', $d->age <= 90 => '61-90', default => '90+'
            };
            $d->overdue = $d->age > $terms;
            $buckets[$d->bucket] += $d->due;

            return $d;
        })->sortByDesc('age')->values();

        return ['rows' => $docs, 'buckets' => $buckets, 'overdue_days' => $terms, 'total' => array_sum($buckets)];
    }

    /** Headline figures for the financial dashboard. */
    public function dashboard(array $branchIds, string $from, string $to): array
    {
        $p = $this->incomeStatement($branchIds, $from, $to);
        $registerAccounts = CashRegister::pluck('account_id')->all();
        $bankAccounts = BankAccount::pluck('account_id')->all();

        return $p + [
            'cash' => $this->sumAccounts($registerAccounts, $branchIds, null, $to),
            'bank' => $this->sumAccounts($bankAccounts, $branchIds, null, $to),
            'card_clearing' => $this->sumAccounts([$this->accounts->id('card.clearing')], $branchIds, null, $to),
            'receivables' => $this->sumAccounts([$this->accounts->id('ar')], $branchIds, null, $to),
            'payables' => -$this->sumAccounts([$this->accounts->id('ap')], $branchIds, null, $to),
            'vat_payable' => -$this->sumAccounts([$this->accounts->id('vat.output')], $branchIds, null, $to) - $this->sumAccounts([$this->accounts->id('vat.input')], $branchIds, null, $to),
            'branches' => $this->branchPerformance($branchIds, $from, $to),
        ];
    }

    /** Debit-positive balance of a set of accounts. */
    public function sumAccounts(array $accountIds, array $branchIds, ?string $from, ?string $to): int
    {
        if ($accountIds === []) {
            return 0;
        }

        return (int) $this->lines($branchIds, $from, $to)->whereIn('journal_lines.account_id', $accountIds)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) - COALESCE(SUM(journal_lines.credit),0) as b')->value('b');
    }

    /** @return list<int> */
    public function cashAccountIds(): array
    {
        return array_values(array_unique(array_merge(
            CashRegister::pluck('account_id')->all(),
            BankAccount::pluck('account_id')->all(),
            [$this->accounts->id('card.clearing')],
        )));
    }

    /** @return array<int, array{d:int,c:int}> */
    private function closingTotals(array $branchIds, string $from, string $to): array
    {
        return $this->lines($branchIds, $from, $to)->where('journal_entries.source_type', 'fiscal_year')
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) as d, SUM(journal_lines.credit) as c')
            ->get()->mapWithKeys(fn ($r) => [(int) $r->account_id => ['d' => (int) $r->d, 'c' => (int) $r->c]])->all();
    }

    /** Proof that the books balance: Σ debits = Σ credits over every posted line. */
    public function isLedgerBalanced(?array $branchIds = null): bool
    {
        $q = JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->when($branchIds, fn ($w) => $w->whereIn('journal_lines.branch_id', $branchIds));

        return (int) (clone $q)->sum('journal_lines.debit') === (int) (clone $q)->sum('journal_lines.credit');
    }
}
