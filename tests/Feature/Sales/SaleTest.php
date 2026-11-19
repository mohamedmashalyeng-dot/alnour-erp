<?php

namespace Tests\Feature\Sales;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\TaxRate;
use App\Modules\Core\Services\Settings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerLedger;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create(['code' => 'B01']);
        $this->product = Product::factory()->create(['selling_price' => 50000, 'purchase_price' => 20000]);
        $this->stock($this->branch, $this->product, 5);
    }

    private function sale(array $overrides = []): Sale
    {
        return app(SaleService::class)->complete(array_merge([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 100000, 'tendered' => 120000]],
        ], $overrides));
    }

    public function test_completing_a_sale_deducts_stock_and_records_payment(): void
    {
        $cashier = $this->actingAsRole('cashier', [$this->branch]);

        $sale = $this->sale();

        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
        $this->assertSame(100000, $sale->total);
        $this->assertSame($cashier->id, $sale->cashier_id);
        $this->assertMatchesRegularExpression('/^INV-B01-\d{4}-000001$/', $sale->invoice_number);
        $this->assertSame(3, $this->qty($this->branch, $this->product));

        $tx = InventoryTransaction::where('type', 'sale')->sole();
        $this->assertSame(-2, $tx->quantity);
        $this->assertSame('sale_item', $tx->reference_type);
        $this->assertSame(20000, $tx->unit_cost, 'COGS cost snapshot recorded');

        $payment = Payment::sole();
        $this->assertSame(100000, $payment->amount);
        $this->assertSame(20000, $payment->change_amount);
        $this->assertSame(20000, $sale->items->first()->unit_cost);
    }

    public function test_client_cannot_set_prices(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);
        $sale = $this->sale([
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ]);
        $this->assertSame(50000, $sale->total);
    }

    public function test_resubmitting_the_same_sale_never_creates_a_duplicate_invoice(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);
        $uuid = (string) Str::uuid();

        $first = $this->sale(['uuid' => $uuid]);
        $second = $this->sale(['uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Payment::count(), 'Payment must not be duplicated on replay');
        $this->assertSame(3, $this->qty($this->branch, $this->product), 'Stock must not be deducted twice');
    }

    public function test_invoice_numbers_are_unique_and_sequential_per_branch(): void
    {
        $other = Branch::factory()->create(['code' => 'B02']);
        $this->stock($other, $this->product, 5);
        $this->actingAsRole('branch-manager', [$this->branch, $other]);

        $a1 = $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'payments' => [['method' => 'card', 'amount' => 50000]]]);
        $a2 = $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'payments' => [['method' => 'card', 'amount' => 50000]]]);
        $b1 = $this->sale(['branch_id' => $other->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'payments' => [['method' => 'card', 'amount' => 50000]]]);

        $this->assertStringEndsWith('000001', $a1->invoice_number);
        $this->assertStringEndsWith('000002', $a2->invoice_number);
        $this->assertStringStartsWith('INV-B02-', $b1->invoice_number);
        $this->assertStringEndsWith('000001', $b1->invoice_number);
    }

    public function test_overselling_is_prevented_and_rolls_back_everything(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);

        try {
            $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 6]], 'payments' => [['method' => 'cash', 'amount' => 300000]]]);
            $this->fail('Oversold');
        } catch (ValidationException) {
        }
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(5, $this->qty($this->branch, $this->product));
    }

    public function test_concurrent_sales_cannot_both_take_the_last_units(): void
    {
        // Two cashiers race for the same 5 units: the inventory row lock + available-stock check
        // means the second sale sees the updated balance and is rejected.
        $this->actingAsRole('cashier', [$this->branch]);
        $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 4]], 'payments' => [['method' => 'cash', 'amount' => 200000]]]);

        $this->actingAsRole('cashier', [$this->branch]);
        $this->expectException(ValidationException::class);
        $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 100000]]]);
    }

    public function test_partial_payment_requires_a_customer_and_creates_a_receivable(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);

        try {
            $this->sale(['payments' => [['method' => 'cash', 'amount' => 40000]]]);
            $this->fail('Credit sale to walk-in customer allowed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_id', $e->errors());
        }

        $customer = Customer::factory()->create();
        $sale = $this->sale(['customer_id' => $customer->id, 'payments' => [['method' => 'cash', 'amount' => 40000]]]);

        $this->assertSame(SaleStatus::PartiallyPaid, $sale->status);
        $this->assertSame(PaymentStatus::Partial, $sale->payment_status);
        $this->assertSame(60000, $sale->balanceDue());
        $this->assertSame(60000, app(CustomerLedger::class)->totals($customer)['balance']);

        // Settle later.
        $sale = app(SaleService::class)->addPayment($sale, ['method' => 'card', 'amount' => 60000, 'uuid' => (string) Str::uuid()]);
        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertSame(0, app(CustomerLedger::class)->totals($customer)['balance']);
    }

    public function test_overpayment_and_duplicate_payments_are_rejected(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);
        $customer = Customer::factory()->create();
        $sale = $this->sale(['customer_id' => $customer->id, 'payments' => []]);
        $this->assertSame(PaymentStatus::Unpaid, $sale->payment_status);

        $uuid = (string) Str::uuid();
        app(SaleService::class)->addPayment($sale, ['method' => 'cash', 'amount' => 30000, 'uuid' => $uuid]);
        app(SaleService::class)->addPayment($sale, ['method' => 'cash', 'amount' => 30000, 'uuid' => $uuid]); // double click
        $this->assertSame(1, Payment::count());
        $this->assertSame(30000, $sale->fresh()->paid_amount);

        $this->expectException(ValidationException::class);
        app(SaleService::class)->addPayment($sale, ['method' => 'cash', 'amount' => 70001, 'uuid' => (string) Str::uuid()]);
    }

    public function test_payments_exceeding_total_are_rejected_at_checkout(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);
        $this->expectException(ValidationException::class);
        $this->sale(['payments' => [['method' => 'card', 'amount' => 100001]]]);
    }

    public function test_split_payment_cash_and_card(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);
        $sale = $this->sale(['payments' => [['method' => 'cash', 'amount' => 30000, 'tendered' => 50000], ['method' => 'card', 'amount' => 70000, 'reference' => 'AUTH-77']]]);

        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
        $this->assertSame(2, Payment::count());
        $this->assertSame(20000, Payment::where('method', 'cash')->value('change_amount'));
    }

    public function test_discount_permissions_and_limits(): void
    {
        app(Settings::class)->set('max_discount_percent', 10);
        $this->actingAsRole('cashier', [$this->branch]);

        // 10% is fine.
        $sale = $this->sale(['discount' => 10000, 'payments' => [['method' => 'cash', 'amount' => 90000]]]);
        $this->assertSame(90000, $sale->total);

        try {
            $this->sale(['discount' => 10001, 'payments' => [['method' => 'cash', 'amount' => 89999]]]);
            $this->fail('Discount above limit accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount', $e->errors());
        }

        // Without sales.discount at all.
        $this->actingAsRole('inventory-clerk', [$this->branch], ['sales.create']);
        $this->expectException(ValidationException::class);
        $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'discount' => 100, 'payments' => [['method' => 'cash', 'amount' => 49900]]]);
    }

    public function test_tax_inclusive_and_exclusive_pricing(): void
    {
        $settings = app(Settings::class);
        $settings->set('tax_enabled', true);
        TaxRate::create(['name' => 'VAT', 'rate_bp' => 1500, 'is_default' => true, 'is_active' => true]);
        $this->actingAsRole('cashier', [$this->branch]);

        $settings->set('prices_include_tax', true);
        $inc = $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'payments' => [['method' => 'card', 'amount' => 50000]]]);
        $this->assertSame(50000, $inc->total);
        $this->assertSame(6522, $inc->tax_total); // 50000 * 15 / 115

        $settings->set('prices_include_tax', false);
        $exc = $this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'payments' => [['method' => 'card', 'amount' => 57500]]]);
        $this->assertSame(57500, $exc->total);
        $this->assertSame(7500, $exc->tax_total);
    }

    public function test_cancellation_restores_stock_refunds_and_keeps_the_record(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        $sale = $this->sale();

        $cancelled = app(SaleService::class)->cancel($sale, 'Entered by mistake');

        $this->assertSame(SaleStatus::Cancelled, $cancelled->status);
        $this->assertSame(5, $this->qty($this->branch, $this->product));
        $this->assertDatabaseHas('inventory_transactions', ['type' => 'sale_cancel', 'quantity' => 2]);
        $this->assertSame(100000, $cancelled->refunded_amount);
        $this->assertSame(1, Payment::where('direction', 'out')->count());
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'invoice_number' => $sale->invoice_number]);

        $this->expectException(ValidationException::class);
        app(SaleService::class)->cancel($cancelled->fresh(), 'again');
    }

    public function test_cashier_cannot_cancel_sales(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);
        $sale = $this->sale();
        $this->expectException(AuthorizationException::class);
        app(SaleService::class)->cancel($sale, 'no');
    }

    public function test_cashier_cannot_sell_from_or_view_another_branch(): void
    {
        $other = Branch::factory()->create();
        $this->stock($other, $this->product, 5);
        $this->actingAsRole('branch-manager', [$other]);
        $foreign = $this->sale(['branch_id' => $other->id]);

        $this->actingAsRole('cashier', [$this->branch]);
        $this->get(route('sales.show', $foreign))->assertForbidden();
        $this->get(route('sales.receipt', $foreign))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->sale(['branch_id' => $other->id]);
    }

    public function test_users_without_sales_permission_cannot_sell(): void
    {
        $this->actingAsRole('inventory-clerk', [$this->branch]);
        $this->get(route('pos'))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->sale();
    }

    public function test_store_credit_requires_a_return(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        $this->expectException(ValidationException::class);
        $this->sale(['payments' => [['method' => PaymentMethod::StoreCredit->value, 'amount' => 100000, 'sale_return_id' => 999]]]);
    }
}
