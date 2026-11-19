<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\FinancialReports;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\Settings;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payroll\Services\PayrollService;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Treasury\Models\CashRegister;
use App\Modules\Treasury\Models\CashShift;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TreasuryExpensePayrollTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
    }

    private function treasury(): TreasuryService
    {
        return app(TreasuryService::class);
    }

    private function fundRegister(int $amount): CashRegister
    {
        $register = $this->treasury()->defaultRegister($this->branch->id);
        $current = auth()->user();
        $this->actingAsRole('admin', [$this->branch]);
        $this->treasury()->recordMovement(['type' => 'deposit', 'branch_id' => $this->branch->id, 'amount' => $amount, 'to' => $register,
            'counter_account_id' => app(AccountResolver::class)->id('owner.capital')]);
        if ($current) {
            $this->actingAs($current);
        }

        return $register;
    }

    public function test_cash_sales_require_an_open_shift_when_configured(): void
    {
        app(Settings::class)->set('cash_shift_required', true);
        $product = Product::factory()->create(['selling_price' => 10000]);
        $this->stock($this->branch, $product, 5);
        $this->actingAsRole('cashier', [$this->branch]);
        $sale = fn () => app(SaleService::class)->complete(['uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10000]]]);

        try {
            $sale();
            $this->fail('Cash taken without a shift');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payments', $e->errors());
        }

        $register = $this->treasury()->defaultRegister($this->branch->id);
        $shift = $this->treasury()->openShift($register, 0);
        $sale();
        $this->assertSame($shift->id, Payment::sole()->cash_shift_id);
        $this->assertSame(10000, $this->treasury()->expectedCash($shift));
    }

    public function test_shift_close_reconciles_cash_and_posts_the_variance(): void
    {
        $register = $this->fundRegister(50000);
        $this->actingAsRole('cashier', [$this->branch]);
        $shift = $this->treasury()->openShift($register, 50000);
        $this->assertSame(0, JournalEntry::where('source_type', 'cash_shift')->count(), 'Float matches the ledger: no variance');

        $closed = $this->treasury()->closeShift($shift, 49000); // 10.00 short
        $this->assertSame(50000, $closed->expected_cash);
        $this->assertSame(-1000, $closed->variance);
        $this->assertSame(49000, $this->treasury()->balance($register->account_id));
        $this->assertSame(1000, app(FinancialReports::class)->sumAccounts([app(AccountResolver::class)->id('cash.over_short')], [$this->branch->id], null, null));

        $this->expectException(ValidationException::class);
        $this->treasury()->closeShift($closed, 49000);
    }

    public function test_only_one_open_shift_per_register(): void
    {
        $register = $this->treasury()->defaultRegister($this->branch->id);
        $this->actingAsRole('cashier', [$this->branch]);
        $this->treasury()->openShift($register, 0);
        $this->actingAsRole('cashier', [$this->branch]);
        $this->expectException(ValidationException::class);
        $this->treasury()->openShift($register, 0);
    }

    public function test_cash_to_bank_transfer_and_insufficient_balance(): void
    {
        $register = $this->fundRegister(30000);
        $this->actingAsRole('accountant', [$this->branch]);
        $this->actingAsRole('branch-manager', [$this->branch], ['bank.manage']);
        $bank = $this->treasury()->defaultBank();

        $this->treasury()->recordMovement(['type' => 'transfer', 'branch_id' => $this->branch->id, 'amount' => 20000, 'from' => $register, 'to' => $bank]);
        $this->assertSame(10000, $this->treasury()->balance($register->account_id));
        $this->assertSame(20000, $this->treasury()->balance($bank->account_id));

        $this->expectException(ValidationException::class);
        $this->treasury()->recordMovement(['type' => 'transfer', 'branch_id' => $this->branch->id, 'amount' => 10001, 'from' => $register, 'to' => $bank]);
    }

    public function test_bank_reconciliation_must_match_the_statement(): void
    {
        $this->actingAsRole('admin', [$this->branch]);
        $bank = $this->treasury()->defaultBank();
        $capital = app(AccountResolver::class)->id('owner.capital');
        $this->treasury()->recordMovement(['type' => 'deposit', 'branch_id' => $this->branch->id, 'amount' => 100000, 'to' => $bank, 'counter_account_id' => $capital]);
        $this->treasury()->recordMovement(['type' => 'withdrawal', 'branch_id' => $this->branch->id, 'amount' => 2500, 'from' => $bank, 'counter_account_id' => Account::where('code', '6950')->value('id')]);

        $lines = $this->treasury()->unreconciledLines($bank);
        $this->assertCount(2, $lines);
        $deposit = $lines->firstWhere('debit', 100000);

        try {
            $this->treasury()->reconcile($bank, today()->toDateString(), 97500, [$deposit->id]);
            $this->fail('Out-of-balance reconciliation accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('statement_balance', $e->errors());
        }

        $this->treasury()->reconcile($bank, today()->toDateString(), 100000, [$deposit->id]);
        $this->assertCount(1, $this->treasury()->unreconciledLines($bank));
        $this->treasury()->reconcile($bank, today()->toDateString(), 97500, [$this->treasury()->unreconciledLines($bank)->first()->id]);
        $this->assertSame(97500, $this->treasury()->reconciledBalance($bank));
    }

    public function test_expense_approval_workflow_and_posting(): void
    {
        Storage::fake('local');
        $register = $this->fundRegister(50000);
        $category = ExpenseCategory::where('code', 'POWER')->firstOrFail();

        $this->actingAsRole('branch-manager', [$this->branch]);
        $svc = app(ExpenseService::class);
        $expense = $svc->save(['branch_id' => $this->branch->id, 'expense_category_id' => $category->id, 'expense_date' => today()->toDateString(),
            'description' => 'Electricity bill', 'amount' => 20000, 'tax_amount' => 3000, 'payment_source' => 'cash', 'cash_register_id' => $register->id]);
        $svc->attach($expense, UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'));
        Storage::disk('local')->assertExists($expense->attachments()->first()->path);
        $svc->submit($expense);

        // Nothing posted before approval, and the creator cannot approve.
        $this->assertSame(0, JournalEntry::where('source_type', 'expense')->count());
        try {
            $svc->approve($expense);
            $this->fail('Branch manager approved an expense');
        } catch (AuthorizationException) {
        }

        $this->actingAsRole('accountant', [$this->branch]);
        $svc->approve($expense->fresh());
        $pl = app(FinancialReports::class)->incomeStatement([$this->branch->id], today()->toDateString(), today()->toDateString());
        $this->assertSame(20000, $pl['total_expenses']);
        $this->assertSame(27000, $this->treasury()->balance($register->account_id));
        $this->assertSame(3000, app(FinancialReports::class)->sumAccounts([app(AccountResolver::class)->id('vat.input')], [$this->branch->id], null, null));

        $this->expectException(\LogicException::class);
        $expense->fresh()->update(['amount' => 1]);
    }

    public function test_approver_cannot_approve_own_expense_and_rejected_is_not_posted(): void
    {
        $this->actingAsRole('accountant', [$this->branch]);
        $svc = app(ExpenseService::class);
        $category = ExpenseCategory::where('code', 'RENT')->firstOrFail();
        $e = $svc->submit($svc->save(['branch_id' => $this->branch->id, 'expense_category_id' => $category->id, 'expense_date' => today()->toDateString(),
            'description' => 'Rent', 'amount' => 500000, 'payment_source' => 'payable']));

        try {
            $svc->approve($e);
            $this->fail('Self-approval allowed');
        } catch (ValidationException) {
        }
        $this->actingAsRole('admin', [$this->branch]);
        $svc->reject($e, 'Wrong month');
        $this->assertSame('rejected', $e->fresh()->status);
        $this->assertSame(0, JournalEntry::where('source_type', 'expense')->count());
    }

    public function test_cashier_cannot_create_expenses(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);
        $this->expectException(AuthorizationException::class);
        app(ExpenseService::class)->save(['branch_id' => $this->branch->id, 'expense_category_id' => ExpenseCategory::first()->id,
            'expense_date' => today()->toDateString(), 'description' => 'x', 'amount' => 1, 'payment_source' => 'payable']);
    }

    public function test_payroll_with_advances_bonuses_and_payment(): void
    {
        $register = $this->fundRegister(1000000);
        $this->actingAsRole('accountant', [$this->branch]);
        $payroll = app(PayrollService::class);
        $emp = $payroll->saveEmployee(['branch_id' => $this->branch->id, 'name' => 'Optician', 'base_salary' => 600000, 'is_active' => true]);
        $payroll->giveAdvance($emp, 100000, 'cash', $register->id, null);
        $this->assertSame(100000, $emp->outstandingAdvances());

        $run = $payroll->createRun($this->branch->id, (int) now()->format('Y'), (int) now()->format('n'));
        $item = $run->items()->sole();
        $payroll->updateItem($item, 50000, 20000, 100000);
        $this->assertSame(530000, $item->fresh()->net_pay); // 6000 + 500 − 200 − 1000

        try {
            $payroll->updateItem($item->fresh(), 0, 0, 100001);
            $this->fail('Recovery above outstanding advance accepted');
        } catch (ValidationException) {
        }

        $payroll->approve($run);
        $this->assertSame(0, $emp->fresh()->outstandingAdvances());
        $payroll->pay($run->fresh(), 'cash', $register->id, null);

        $pl = app(FinancialReports::class)->incomeStatement([$this->branch->id], now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());
        $this->assertSame(630000, $pl['total_expenses']); // salaries 5800 + bonus 500
        $this->assertSame(1000000 - 100000 - 530000, $this->treasury()->balance($register->account_id));
        $this->assertSame(0, app(FinancialReports::class)->sumAccounts([app(AccountResolver::class)->id('salaries.payable')], [$this->branch->id], null, null));
        $this->assertSame(0, app(FinancialReports::class)->sumAccounts([app(AccountResolver::class)->id('employee.advances')], [$this->branch->id], null, null));

        $this->expectException(ValidationException::class);
        $payroll->pay($run->fresh(), 'cash', $register->id, null);
    }

    public function test_salary_data_is_restricted(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        $this->get(route('payroll.employees'))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(PayrollService::class)->saveEmployee(['branch_id' => $this->branch->id, 'name' => 'X', 'base_salary' => 1]);
    }

    public function test_open_shift_record(): void
    {
        $register = $this->treasury()->defaultRegister($this->branch->id);
        $this->actingAsRole('cashier', [$this->branch]);
        $shift = $this->treasury()->openShift($register, 0);
        $this->assertSame('open', CashShift::find($shift->id)->status);
        $this->assertSame($shift->id, $this->treasury()->openShiftFor(auth()->id(), $this->branch->id)->id);
    }
}
