<?php

namespace Tests\Feature\Purchasing;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\Settings;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Services\SupplierLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->supplier = Supplier::factory()->create();
        $this->product = Product::factory()->create(['purchase_price' => 10000]);
        $this->product->forceFill(['cost_price' => 10000])->save();
    }

    private function po(array $overrides = []): PurchaseOrder
    {
        return app(PurchaseService::class)->saveDraft(array_merge([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'order_date' => today()->toDateString(),
            'supplier_invoice_number' => 'SUP-'.Str::random(6),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 12000, 'discount' => 0]],
        ], $overrides));
    }

    public function test_receiving_a_purchase_increases_stock_exactly_once(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        $svc = app(PurchaseService::class);
        $po = $svc->confirm($this->po());
        $this->assertSame(0, $this->qty($this->branch, $this->product), 'Confirming does not touch stock');

        $po = $svc->receive($po);
        $this->assertSame(PurchaseStatus::Received, $po->status);
        $this->assertSame(10, $this->qty($this->branch, $this->product));
        $this->assertSame(1, InventoryTransaction::where('type', 'purchase_receipt')->count());

        try {
            $svc->receive($po);
            $this->fail('Received twice');
        } catch (ValidationException) {
        }
        $this->assertSame(10, $this->qty($this->branch, $this->product));
        $this->assertSame(1, InventoryTransaction::where('type', 'purchase_receipt')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'purchase.received', 'auditable_id' => $po->id]);
    }

    public function test_draft_purchases_cannot_be_received(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        $this->expectException(ValidationException::class);
        app(PurchaseService::class)->receive($this->po());
    }

    public function test_weighted_average_cost_is_updated_on_receipt(): void
    {
        $this->stock($this->branch, $this->product, 10); // 10 units @ 100.00
        $this->actingAsRole('branch-manager', [$this->branch]);
        $svc = app(PurchaseService::class);
        $svc->receive($svc->confirm($this->po())); // 10 units @ 120.00

        $this->assertSame(11000, $this->product->fresh()->cost_price); // (10*100 + 10*120)/20
        $this->assertSame(12000, $this->product->fresh()->purchase_price);
    }

    public function test_last_purchase_costing_method(): void
    {
        app(Settings::class)->set('costing_method', 'last_purchase');
        $this->stock($this->branch, $this->product, 10);
        $this->actingAsRole('branch-manager', [$this->branch]);
        $svc = app(PurchaseService::class);
        $svc->receive($svc->confirm($this->po()));

        $this->assertSame(12000, $this->product->fresh()->cost_price);
    }

    public function test_totals_with_discounts(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        $po = $this->po(['discount' => 2000, 'lines' => [
            ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 12000, 'discount' => 10000],
        ]]);
        $this->assertSame(120000, $po->subtotal);
        $this->assertSame(12000, $po->discount_total);
        $this->assertSame(108000, $po->total);
        $this->assertSame(10800, $po->items->first()->netUnitCost());
    }

    public function test_supplier_partial_payments_and_balance(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        $svc = app(PurchaseService::class);
        $po = $svc->receive($svc->confirm($this->po()));
        $ledger = app(SupplierLedger::class);
        $this->assertSame(120000, $ledger->totals($this->supplier)['balance']);

        $payments = app(PaymentService::class);
        $payments->paySupplier($this->supplier, $this->branch, PaymentMethod::BankTransfer, [$po->id => 50000], ['uuid' => (string) Str::uuid()]);
        $this->assertSame(PaymentStatus::Partial, $po->fresh()->payment_status);
        $this->assertSame(70000, $ledger->totals($this->supplier)['balance']);

        $payments->paySupplierOnAccount($this->supplier, $this->branch, PaymentMethod::Cash, 70000);
        $this->assertSame(PaymentStatus::Paid, $po->fresh()->payment_status);
        $this->assertSame(0, $ledger->totals($this->supplier)['balance']);

        $statement = $ledger->statement($this->supplier);
        $this->assertCount(3, $statement['rows']);
        $this->assertSame(0, $statement['closing']);

        $this->expectException(ValidationException::class);
        $payments->paySupplierOnAccount($this->supplier, $this->branch, PaymentMethod::Cash, 1);
    }

    public function test_duplicate_supplier_invoice_numbers_are_rejected(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        $this->po(['supplier_invoice_number' => 'INV-77']);
        $this->expectException(ValidationException::class);
        $this->po(['supplier_invoice_number' => 'INV-77']);
    }

    public function test_paid_or_received_orders_cannot_be_cancelled(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        $svc = app(PurchaseService::class);
        $po = $svc->confirm($this->po());
        app(PaymentService::class)->paySupplier($this->supplier, $this->branch, PaymentMethod::Cash, [$po->id => 1000]);

        $this->expectException(ValidationException::class);
        $svc->cancel($po, 'Supplier out of stock');
    }

    public function test_unauthorized_users_cannot_create_or_receive_purchases(): void
    {
        $this->actingAsRole('branch-manager', [$this->branch]);
        $po = app(PurchaseService::class)->confirm($this->po());

        $this->actingAsRole('cashier', [$this->branch]);
        try {
            $this->po();
            $this->fail('Cashier created a PO');
        } catch (AuthorizationException) {
        }
        $this->get(route('purchases.index'))->assertForbidden();

        // Inventory clerk may receive, but only in their own branch.
        $other = Branch::factory()->create();
        $this->actingAsRole('inventory-clerk', [$other]);
        $this->expectException(AuthorizationException::class);
        app(PurchaseService::class)->receive($po);
    }

    public function test_cashier_cannot_record_supplier_payments(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);
        $this->get(route('suppliers.show', $this->supplier))->assertForbidden();
    }
}
