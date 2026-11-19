<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Livewire\JournalForm;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Attachment;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Payroll\Services\PayrollService;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AccountingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_accounting_page_renders_with_data(): void
    {
        Storage::fake('local');
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->stock($branch, $product, 5);
        $this->actingAsSuperAdmin();

        app(SaleService::class)->complete(['uuid' => (string) Str::uuid(), 'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => $product->selling_price]]]);
        $svc = app(ExpenseService::class);
        $expense = $svc->save(['branch_id' => $branch->id, 'expense_category_id' => ExpenseCategory::first()->id, 'expense_date' => today()->toDateString(),
            'description' => 'Internet', 'amount' => 1000, 'payment_source' => 'payable']);
        $attachment = $svc->attach($expense, UploadedFile::fake()->create('r.pdf', 10, 'application/pdf'));
        $payroll = app(PayrollService::class);
        $payroll->saveEmployee(['branch_id' => $branch->id, 'name' => 'E', 'base_salary' => 1000, 'is_active' => true]);
        $run = $payroll->createRun($branch->id, (int) now()->format('Y'), (int) now()->format('n'));
        $bank = app(TreasuryService::class)->defaultBank();
        $entry = JournalEntry::first();

        $pages = [
            route('accounting.dashboard', ['branch' => 0]), route('accounting.accounts'), route('accounting.journal'), route('accounting.journal.create'),
            route('accounting.journal.show', $entry), route('accounting.periods'), route('accounting.trial-balance'),
            route('accounting.ledger', ['account' => Account::where('code', '1130')->value('id')]), route('accounting.income-statement'),
            route('accounting.income-statement', ['mode' => 'monthly']), route('accounting.income-statement', ['mode' => 'annual']),
            route('accounting.balance-sheet'), route('accounting.cash-flow'), route('accounting.vat'),
            route('accounting.aging'), route('accounting.aging', ['type' => 'payables']), route('accounting.revenue', ['group' => 'month']),
            route('cash.index'), route('bank.index'), route('bank.reconcile', $bank),
            route('expenses.index'), route('expenses.create'), route('expenses.show', $expense), route('expenses.edit', $expense),
            route('payroll.index'), route('payroll.employees'), route('payroll.show', $run), route('pos'), route('settings.index'),
        ];
        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('attachments.show', $attachment))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Manual journal UI: unbalanced is refused, balanced is saved as draft.
        Livewire::test(JournalForm::class)
            ->set('memo', 'Test')->set('branchId', $branch->id)
            ->set('lines.0.account_id', (string) Account::where('code', '6400')->value('id'))->set('lines.0.debit', '10')
            ->set('lines.1.account_id', (string) Account::where('code', '2130')->value('id'))->set('lines.1.credit', '9')
            ->call('save')->assertHasErrors('lines')
            ->set('lines.1.credit', '10')->call('save')->assertHasNoErrors();
        $this->assertSame(1, JournalEntry::where('status', 'draft')->count());
    }

    public function test_attachments_are_private(): void
    {
        Storage::fake('local');
        $a = Branch::factory()->create();
        $b = Branch::factory()->create();
        $this->actingAsRole('branch-manager', [$a]);
        $svc = app(ExpenseService::class);
        $e = $svc->save(['branch_id' => $a->id, 'expense_category_id' => ExpenseCategory::first()->id, 'expense_date' => today()->toDateString(),
            'description' => 'x', 'amount' => 100, 'payment_source' => 'payable']);
        $att = $svc->attach($e, UploadedFile::fake()->create('r.pdf', 10, 'application/pdf'));
        $this->assertFalse(Storage::disk('public')->exists($att->path));

        $this->actingAsRole('branch-manager', [$b]);
        $this->get(route('attachments.show', $att))->assertForbidden();
        $this->actingAsRole('cashier', [$a]);
        $this->get(route('attachments.show', $att))->assertForbidden();
        $this->assertSame(1, Attachment::count());
    }
}
