<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\FinancialReports;
use App\Modules\Accounting\Services\LedgerService;
use App\Modules\Accounting\Services\PeriodService;
use App\Modules\Branches\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->actingAsRole('accountant', [$this->branch]);
    }

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function entry(int $debit = 1000, int $credit = 1000, string $date = 'today'): JournalEntry
    {
        return $this->ledger()->post(['entry_date' => now()->parse($date)->toDateString(), 'branch_id' => $this->branch->id, 'memo' => 'test'], [
            ['account' => 'owner.capital', 'credit' => $credit],
            ['account' => 'inventory', 'debit' => $debit],
        ]);
    }

    public function test_balanced_entry_is_posted(): void
    {
        $e = $this->entry();
        $this->assertSame('posted', $e->status);
        $this->assertSame(1000, $e->total);
        $this->assertCount(2, $e->lines);
        $this->assertStringStartsWith('JE-', $e->number);
    }

    public function test_unbalanced_entry_is_never_posted(): void
    {
        $this->expectException(ValidationException::class);
        try {
            $this->entry(1000, 999);
        } finally {
            $this->assertSame(0, JournalEntry::count());
        }
    }

    public function test_line_with_both_sides_or_single_line_is_rejected(): void
    {
        foreach ([
            [['account' => 'inventory', 'debit' => 5, 'credit' => 5], ['account' => 'ar', 'debit' => 0, 'credit' => 0]],
            [['account' => 'inventory', 'debit' => 5]],
            [['account' => 'inventory', 'debit' => -5], ['account' => 'ar', 'credit' => -5]],
        ] as $lines) {
            try {
                $this->ledger()->post(['entry_date' => now(), 'branch_id' => $this->branch->id], $lines);
                $this->fail('Invalid entry posted');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_header_and_inactive_accounts_cannot_receive_postings(): void
    {
        $this->expectException(ValidationException::class);
        $this->ledger()->post(['entry_date' => now(), 'branch_id' => $this->branch->id], [
            ['account' => Account::where('code', '1100')->value('id'), 'debit' => 10],
            ['account' => 'ar', 'credit' => 10],
        ]);
    }

    public function test_posting_is_idempotent_per_source_event(): void
    {
        $source = Branch::factory()->create();
        $header = ['entry_date' => now(), 'branch_id' => $this->branch->id, 'source' => $source, 'event' => 'test'];
        $lines = [['account' => 'inventory', 'debit' => 10], ['account' => 'ar', 'credit' => 10]];

        $a = $this->ledger()->post($header, $lines);
        $b = $this->ledger()->post($header, $lines);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_reversal_mirrors_the_entry_and_is_idempotent(): void
    {
        $e = $this->entry();
        $r1 = $this->ledger()->reverse($e, 'Error');
        $r2 = $this->ledger()->reverse($e->fresh(), 'Again');

        $this->assertSame($r1->id, $r2->id);
        $this->assertSame('reversed', $e->fresh()->status);
        $this->assertSame($e->id, $r1->reversal_of_id);
        $inventory = app(AccountResolver::class)->id('inventory');
        $this->assertSame(0, (int) JournalLine::where('account_id', $inventory)->sum('debit') - (int) JournalLine::where('account_id', $inventory)->sum('credit'));
        $this->assertTrue(app(FinancialReports::class)->isLedgerBalanced());
    }

    public function test_posted_entries_and_lines_are_immutable(): void
    {
        $e = $this->entry();
        try {
            $e->update(['memo' => 'changed']);
            $this->fail('Posted entry edited');
        } catch (LogicException) {
        }
        try {
            $e->lines->first()->update(['debit' => 1]);
            $this->fail('Posted line edited');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $e->delete();
    }

    public function test_closed_period_rejects_postings_and_can_be_reopened_with_permission(): void
    {
        $periods = app(PeriodService::class);
        $periods->close((int) now()->format('Y'), (int) now()->format('n'));

        try {
            $this->entry();
            $this->fail('Posted into a closed period');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('entry_date', $e->errors());
        }

        // Accountant can close but not reopen.
        try {
            $periods->reopen((int) now()->format('Y'), (int) now()->format('n'));
            $this->fail('Reopened without permission');
        } catch (AuthorizationException) {
        }

        $this->actingAsRole('admin', [$this->branch]);
        $periods->reopen((int) now()->format('Y'), (int) now()->format('n'));
        $this->assertSame('posted', $this->entry()->status);
    }

    public function test_year_end_close_moves_profit_to_retained_earnings(): void
    {
        $year = (int) now()->subYear()->format('Y');
        $date = "$year-06-15";
        $this->ledger()->post(['entry_date' => $date, 'branch_id' => $this->branch->id], [
            ['account' => 'ar', 'debit' => 5000], ['account' => 'sales.revenue', 'credit' => 5000],
        ]);
        $this->ledger()->post(['entry_date' => $date, 'branch_id' => $this->branch->id], [
            ['account' => Account::where('code', '6100')->value('id'), 'debit' => 1200], ['account' => 'accrued.expenses', 'credit' => 1200],
        ]);

        app(PeriodService::class)->closeYear($year, $this->ledger(), app(AccountResolver::class));

        $reports = app(FinancialReports::class);
        $bs = $reports->balanceSheet([$this->branch->id], "$year-12-31");
        $re = $bs['equity']->firstWhere('code', '3200');
        $this->assertSame(3800, $re->balance);
        $this->assertSame(0, $bs['current_earnings']);
        $this->assertTrue($bs['balanced']);
        // The closing entry does not hide the year's P&L.
        $this->assertSame(3800, $reports->incomeStatement([$this->branch->id], "$year-01-01", "$year-12-31")['net_profit']);
        $this->expectException(ValidationException::class);
        $this->entry(10, 10, "$year-07-01");
    }

    public function test_manual_journal_draft_then_post_requires_permissions(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        try {
            $this->ledger()->createManual(['entry_date' => now(), 'branch_id' => $this->branch->id], [['account' => 'inventory', 'debit' => 1], ['account' => 'ar', 'credit' => 1]]);
            $this->fail('Branch manager created a journal');
        } catch (AuthorizationException) {
        }

        $this->actingAsRole('accountant', [$this->branch]);
        $draft = $this->ledger()->createManual(['entry_date' => now(), 'branch_id' => $this->branch->id, 'memo' => 'Accrual'], [
            ['account' => Account::where('code', '6300')->value('id'), 'debit' => 700], ['account' => 'accrued.expenses', 'credit' => 700],
        ]);
        $this->assertSame('draft', $draft->status);
        $this->assertSame(0, app(FinancialReports::class)->incomeStatement([$this->branch->id], now()->startOfMonth()->toDateString(), now()->toDateString())['total_expenses']);

        $this->ledger()->postDraft($draft);
        $this->assertSame(700, app(FinancialReports::class)->incomeStatement([$this->branch->id], now()->startOfMonth()->toDateString(), now()->toDateString())['total_expenses']);
    }
}
