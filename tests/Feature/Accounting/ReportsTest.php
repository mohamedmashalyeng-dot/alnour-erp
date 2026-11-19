<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FinancialReports;
use App\Modules\Accounting\Services\LedgerService;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;

    private Branch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Branch::factory()->create();
        $this->b = Branch::factory()->create();
        $product = Product::factory()->create(['selling_price' => 100000]);
        $product->forceFill(['cost_price' => 30000])->save();
        $this->stock($this->a, $product, 10);
        $this->stock($this->b, $product, 10);

        // Branch A: 3 sales (one on credit) + rent expense. Branch B: 1 sale.
        $this->actingAsRole('admin', [$this->a, $this->b]);
        $customer = Customer::factory()->create();
        foreach ([[$this->a, 3, null], [$this->b, 1, null]] as [$branch, $n]) {
            for ($i = 0; $i < $n; $i++) {
                app(SaleService::class)->complete(['uuid' => (string) Str::uuid(), 'branch_id' => $branch->id,
                    'customer_id' => $i === 0 ? $customer->id : null,
                    'items' => [['product_id' => $product->id, 'quantity' => 1]],
                    'payments' => $i === 0 ? [] : [['method' => 'card', 'amount' => 100000]]]);
            }
        }
        $svc = app(ExpenseService::class);
        $e = $svc->submit($svc->save(['branch_id' => $this->a->id, 'expense_category_id' => ExpenseCategory::where('code', 'RENT')->value('id'),
            'expense_date' => today()->toDateString(), 'description' => 'Rent', 'amount' => 50000, 'payment_source' => 'payable']));
        $this->actingAsSuperAdmin();
        $svc->approve($e);
    }

    private function reports(): FinancialReports
    {
        return app(FinancialReports::class);
    }

    public function test_profit_and_loss_from_a_known_scenario(): void
    {
        $pl = $this->reports()->incomeStatement([$this->a->id], today()->toDateString(), today()->toDateString());

        $this->assertSame(300000, $pl['total_revenue']);
        $this->assertSame(90000, $pl['total_cogs']);
        $this->assertSame(210000, $pl['gross_profit']);
        $this->assertSame(50000, $pl['total_expenses']);
        $this->assertSame(160000, $pl['net_profit']);
        // Net profit is NOT "sales − purchase payments": no purchase payment exists, yet COGS is recognized.
    }

    public function test_branch_financial_isolation(): void
    {
        $b = $this->reports()->incomeStatement([$this->b->id], today()->toDateString(), today()->toDateString());
        $this->assertSame(100000, $b['total_revenue']);
        $this->assertSame(0, $b['total_expenses']);

        $all = $this->reports()->incomeStatement([$this->a->id, $this->b->id], today()->toDateString(), today()->toDateString());
        $this->assertSame(400000, $all['total_revenue']);

        // A branch-limited user cannot open another branch's report.
        $this->actingAsRole('accountant', [$this->a]);
        $this->actingAsRole('branch-manager', [$this->a], ['accounting.view', 'reports.statements']);
        $this->get(route('accounting.income-statement', ['branch' => $this->b->id]))->assertForbidden();
        $this->get(route('accounting.income-statement', ['branch' => $this->a->id]))->assertOk();
        $this->get(route('accounting.income-statement', ['branch' => 0]))->assertForbidden();
    }

    public function test_trial_balance_and_balance_sheet_balance_per_branch_and_company(): void
    {
        foreach ([[$this->a->id], [$this->b->id], [$this->a->id, $this->b->id]] as $ids) {
            $tb = $this->reports()->trialBalance($ids, today()->toDateString());
            $this->assertSame($tb['debit'], $tb['credit']);
            $this->assertTrue($this->reports()->balanceSheet($ids, today()->toDateString())['balanced']);
        }
    }

    public function test_dashboard_receivables_payables_and_cash_flow(): void
    {
        $d = $this->reports()->dashboard([$this->a->id, $this->b->id], today()->toDateString(), today()->toDateString());
        $this->assertSame(200000, $d['receivables']);     // two credit sales (one per branch)
        $this->assertSame(200000, $d['card_clearing']);
        $this->assertSame(0, $d['payables']);

        $cf = $this->reports()->cashFlow([$this->a->id, $this->b->id], today()->toDateString(), today()->toDateString());
        $this->assertSame(200000, $cf['sections']['operating']['Receipts from customers']);
        $this->assertSame($cf['opening'] + $cf['net_change'], $cf['closing']);

        $aging = $this->reports()->aging('receivables', [$this->a->id, $this->b->id], today()->toDateString());
        $this->assertSame(200000, $aging['buckets']['0-30']);
        $this->assertCount(2, $aging['rows']);
    }

    public function test_general_ledger_running_balance(): void
    {
        $sales = Account::where('code', '4110')->value('id');
        $gl = $this->reports()->generalLedger($sales, [$this->a->id], today()->startOfMonth()->toDateString(), today()->toDateString());
        $this->assertCount(3, $gl['rows']);
        $this->assertSame(300000, $gl['closing']);
    }

    public function test_monthly_comparison_and_revenue_grouping(): void
    {
        $months = $this->reports()->monthlyComparison([$this->a->id], (int) today()->format('Y'));
        $this->assertSame(160000, $months[(int) today()->format('n')]['net_profit']);
        $rev = $this->reports()->revenue([$this->a->id, $this->b->id], today()->startOfYear()->toDateString(), today()->toDateString(), 'month');
        $this->assertSame(400000, $rev->sum('total'));
    }

    public function test_reversal_removes_amounts_from_reports(): void
    {
        $entry = JournalEntry::where('source_type', 'expense')->sole();
        app(LedgerService::class)->reverse($entry, 'Duplicate bill');
        $pl = $this->reports()->incomeStatement([$this->a->id], today()->toDateString(), today()->toDateString());
        $this->assertSame(0, $pl['total_expenses']);
    }
}
