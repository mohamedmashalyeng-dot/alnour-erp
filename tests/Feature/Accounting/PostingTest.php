<?php

namespace Tests\Feature\Accounting;

use App\Console\Commands\PostAccountingHistory;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\FinancialReports;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\TaxRate;
use App\Modules\Core\Services\Settings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerLedger;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Purchasing\Services\PurchaseService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\ReturnService;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Services\SupplierLedger;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->product = Product::factory()->create(['selling_price' => 115000]);
        $this->product->forceFill(['cost_price' => 40000])->save();
    }

    private function balanceOf(string $key, ?array $branches = null): int
    {
        return app(FinancialReports::class)->sumAccounts([app(AccountResolver::class)->id($key)], $branches ?? [$this->branch->id], null, null);
    }

    private function sell(array $overrides = []): Sale
    {
        return app(SaleService::class)->complete(array_merge([
            'uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 115000]],
        ], $overrides));
    }

    public function test_sale_journal_matches_the_documented_example(): void
    {
        app(Settings::class)->set('tax_enabled', true);
        app(Settings::class)->set('prices_include_tax', true);
        TaxRate::create(['name' => 'VAT', 'rate_bp' => 1500, 'is_default' => true, 'is_active' => true]);
        $this->stock($this->branch, $this->product, 3); // opening stock: Dr Inventory 1,200.00 / Cr Opening equity
        $this->actingAsRole('cashier', [$this->branch]);

        $sale = $this->sell();

        $entry = JournalEntry::with('lines.account')->where(['source_type' => 'sale', 'source_id' => $sale->id])->sole();
        $lines = $entry->lines->mapWithKeys(fn ($l) => [$l->account->code => [$l->debit, $l->credit]])->all();
        $this->assertSame([115000, 0], $lines['1130']); // AR
        $this->assertSame([0, 100000], $lines['4110']); // Sales
        $this->assertSame([0, 15000], $lines['2140']);  // VAT output
        $this->assertSame([40000, 0], $lines['5100']);  // COGS
        $this->assertSame([0, 40000], $lines['1140']);  // Inventory

        // Cash payment: Dr cash register / Cr AR → AR settled, cash in the branch's register.
        $this->assertSame(0, $this->balanceOf('ar'));
        $register = app(TreasuryService::class)->defaultRegister($this->branch->id);
        $this->assertSame(115000, app(TreasuryService::class)->balance($register->account_id));
        $this->assertSame(80000, $this->balanceOf('inventory')); // 1,200 − 400
        $this->assertTrue(app(FinancialReports::class)->isLedgerBalanced());
    }

    public function test_card_payments_go_to_clearing_and_partial_payments_leave_receivables(): void
    {
        $this->stock($this->branch, $this->product, 3);
        $this->actingAsRole('cashier', [$this->branch]);
        $customer = Customer::factory()->create();
        $sale = $this->sell(['customer_id' => $customer->id, 'payments' => [['method' => 'card', 'amount' => 50000]]]);

        $this->assertSame(50000, $this->balanceOf('card.clearing'));
        $this->assertSame(65000, $this->balanceOf('ar'));
        // GL receivable equals the document-based customer balance.
        $this->assertSame(app(CustomerLedger::class)->totals($customer)['balance'], $this->balanceOf('ar'));

        app(SaleService::class)->addPayment($sale, ['method' => 'bank_transfer', 'amount' => 65000, 'uuid' => (string) Str::uuid()]);
        $this->assertSame(0, $this->balanceOf('ar'));
        $this->assertSame(65000, app(TreasuryService::class)->balance(app(TreasuryService::class)->defaultBank()->account_id));
    }

    public function test_cancellation_posts_a_reversal_not_a_deletion(): void
    {
        $this->stock($this->branch, $this->product, 3);
        $this->actingAsRole('branch-manager', [$this->branch]);
        $sale = $this->sell();
        $before = JournalEntry::count();

        app(SaleService::class)->cancel($sale, 'Mistake');

        $original = JournalEntry::where(['source_type' => 'sale', 'source_id' => $sale->id])->sole();
        $this->assertSame('reversed', $original->status);
        $this->assertNotNull($original->reversed_by_id);
        $this->assertGreaterThan($before, JournalEntry::count());
        $this->assertSame(0, app(FinancialReports::class)->incomeStatement([$this->branch->id], today()->toDateString(), today()->toDateString())['total_revenue']);
        $this->assertSame(0, $this->balanceOf('ar'));
        $this->assertSame(120000, $this->balanceOf('inventory'));
    }

    public function test_returns_and_exchanges_post_correctly(): void
    {
        $this->stock($this->branch, $this->product, 3);
        $this->actingAsRole('branch-manager', [$this->branch]);
        $sale = $this->sell();
        $svc = app(ReturnService::class);
        $return = $svc->complete($svc->create($sale, [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1, 'condition' => 'good', 'disposition' => 'restock']], 'Changed mind'));
        $svc->refund($return, PaymentMethod::Cash, 115000);

        $pl = app(FinancialReports::class)->incomeStatement([$this->branch->id], today()->toDateString(), today()->toDateString());
        $this->assertSame(0, $pl['total_revenue']);  // sales − returns
        $this->assertSame(0, $pl['total_cogs']);     // restocked → COGS reversed
        $this->assertSame(120000, $this->balanceOf('inventory'));
        $this->assertSame(0, $this->balanceOf('ar'));
        $this->assertTrue(app(FinancialReports::class)->isLedgerBalanced());
    }

    public function test_purchase_and_supplier_payment_postings(): void
    {
        app(Settings::class)->set('tax_enabled', true);
        TaxRate::create(['name' => 'VAT', 'rate_bp' => 1500, 'is_default' => true, 'is_active' => true]);
        $this->actingAsRole('branch-manager', [$this->branch]);
        $supplier = Supplier::factory()->create();
        $svc = app(PurchaseService::class);
        $po = $svc->receive($svc->confirm($svc->saveDraft([
            'supplier_id' => $supplier->id, 'branch_id' => $this->branch->id, 'order_date' => today()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 40000]],
        ])));

        $this->assertSame(400000, $this->balanceOf('inventory'));
        $this->assertSame(60000, $this->balanceOf('vat.input'));
        $this->assertSame(-460000, $this->balanceOf('ap'));

        app(PaymentService::class)->paySupplier($supplier, $this->branch, PaymentMethod::BankTransfer, [$po->id => 200000]);
        $this->assertSame(-260000, $this->balanceOf('ap'));
        $this->assertSame(app(SupplierLedger::class)->totals($supplier)['balance'], -$this->balanceOf('ap'));
    }

    public function test_stock_adjustments_and_transfers_are_valued(): void
    {
        $other = Branch::factory()->create();
        $this->stock($this->branch, $this->product, 10); // 400,000 inventory
        $this->actingAsRole('branch-manager', [$this->branch, $other]);

        app(InventoryService::class)->adjust($this->branch->id, $this->product->id, -2, 'Broken');
        $this->assertSame(80000, $this->balanceOf('inventory.shrinkage'));

        $t = app(StockTransferService::class);
        $t->receive($t->dispatch($t->approve($t->create($this->branch->id, $other->id, [['product_id' => $this->product->id, 'quantity' => 3]]))));

        $this->assertSame(200000, $this->balanceOf('inventory'));               // 8 − 3 = 5 units × 400
        $this->assertSame(120000, $this->balanceOf('inventory', [$other->id])); // 3 units
        $this->assertSame(0, $this->balanceOf('interbranch', [$this->branch->id, $other->id]));
        $this->assertTrue(app(FinancialReports::class)->isLedgerBalanced([$this->branch->id]));
        $this->assertTrue(app(FinancialReports::class)->isLedgerBalanced([$other->id]));
    }

    public function test_failed_posting_rolls_back_the_sale(): void
    {
        $this->stock($this->branch, $this->product, 3);
        DB::table('account_mappings')->where('key', 'sales.revenue')->delete();
        app(AccountResolver::class)->flush();
        $this->actingAsRole('cashier', [$this->branch]);

        try {
            $this->sell();
            $this->fail('Sale committed without its journal');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, Sale::count());
        $this->assertSame(3, $this->qty($this->branch, $this->product));
    }

    public function test_history_backfill_is_idempotent(): void
    {
        $this->stock($this->branch, $this->product, 3);
        $this->actingAsRole('cashier', [$this->branch]);
        $this->sell();
        // Simulate Phase-2 data that predates accounting.
        DB::table('journal_lines')->delete();
        DB::table('journal_entries')->delete();

        $this->artisan(PostAccountingHistory::class)->assertSuccessful();
        $count = JournalEntry::count();
        $this->artisan(PostAccountingHistory::class)->assertSuccessful();

        $this->assertSame($count, JournalEntry::count());
        $this->assertSame(3, $count); // opening stock, sale, payment
        $this->assertTrue(app(FinancialReports::class)->isLedgerBalanced());
    }
}
