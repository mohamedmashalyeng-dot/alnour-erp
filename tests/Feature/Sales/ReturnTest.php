<?php

namespace Tests\Feature\Sales;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerLedger;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Enums\SaleReturnStatus;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\ReturnService;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReturnTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Product $frame;

    private Product $sunglasses;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->frame = Product::factory()->create(['selling_price' => 30000]);
        $this->sunglasses = Product::factory()->create(['selling_price' => 45000]);
        $this->stock($this->branch, $this->frame, 10);
        $this->stock($this->branch, $this->sunglasses, 10);

        $this->actingAsRole('branch-manager', [$this->branch]);
        $this->sale = app(SaleService::class)->complete([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->frame->id, 'quantity' => 3], ['product_id' => $this->sunglasses->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 135000]],
        ]);
    }

    private function returnLine(Product $p, int $qty, string $disposition = 'restock'): array
    {
        $item = $this->sale->items()->where('product_id', $p->id)->firstOrFail();

        return ['sale_item_id' => $item->id, 'quantity' => $qty, 'condition' => 'good', 'disposition' => $disposition];
    }

    public function test_stock_is_restored_only_after_inspection(): void
    {
        $svc = app(ReturnService::class);
        $return = $svc->create($this->sale, [$this->returnLine($this->frame, 1)], 'Wrong size');

        $this->assertSame(SaleReturnStatus::Pending, $return->status);
        $this->assertSame(7, $this->qty($this->branch, $this->frame), 'Pending return must not change stock');

        $svc->complete($return);
        $this->assertSame(8, $this->qty($this->branch, $this->frame));
        $this->assertDatabaseHas('inventory_transactions', ['type' => 'sale_return', 'quantity' => 1, 'product_id' => $this->frame->id]);

        $sale = $this->sale->fresh();
        $this->assertSame(30000, $sale->returned_amount);
        $this->assertSame('partial', $sale->return_status);
        $this->assertSame(30000, $sale->refundable());
        $this->assertDatabaseHas('sales', ['id' => $this->sale->id, 'total' => 135000]); // original untouched
    }

    public function test_damaged_items_are_not_restocked(): void
    {
        $svc = app(ReturnService::class);
        $svc->complete($svc->create($this->sale, [$this->returnLine($this->sunglasses, 1, 'write_off')], 'Scratched lens'));

        $this->assertSame(9, $this->qty($this->branch, $this->sunglasses));
    }

    public function test_cannot_return_more_than_sold_even_across_returns(): void
    {
        $svc = app(ReturnService::class);
        $svc->create($this->sale, [$this->returnLine($this->frame, 2)], 'A');

        $this->expectException(ValidationException::class);
        $svc->create($this->sale->fresh(), [$this->returnLine($this->frame, 2)], 'B');
    }

    public function test_rejecting_a_return_releases_the_quantity(): void
    {
        $svc = app(ReturnService::class);
        $r = $svc->create($this->sale, [$this->returnLine($this->frame, 3)], 'A');
        $svc->reject($r, 'Not our product');

        $again = $svc->create($this->sale->fresh(), [$this->returnLine($this->frame, 3)], 'B');
        $this->assertSame(3, $again->items->sum('quantity'));
        $this->assertSame(7, $this->qty($this->branch, $this->frame));
    }

    public function test_refund_is_limited_to_the_return_credit_and_recorded(): void
    {
        $svc = app(ReturnService::class);
        $r = $svc->complete($svc->create($this->sale, [$this->returnLine($this->frame, 1)], 'Changed mind'));

        try {
            $svc->refund($r, PaymentMethod::Cash, 30001);
            $this->fail('Over-refund allowed');
        } catch (ValidationException) {
        }

        $uuid = (string) Str::uuid();
        $svc->refund($r, PaymentMethod::Cash, 30000, null, $uuid);
        $svc->refund($r, PaymentMethod::Cash, 30000, null, $uuid); // replay
        $this->assertSame(1, Payment::where('direction', 'out')->count());
        $this->assertSame(30000, $r->fresh()->refunded_amount);
        $this->assertSame(0, $this->sale->fresh()->balanceDue());
    }

    public function test_full_return_marks_sale_refunded(): void
    {
        $svc = app(ReturnService::class);
        $r = $svc->complete($svc->create($this->sale, [$this->returnLine($this->frame, 3), $this->returnLine($this->sunglasses, 1)], 'Defective batch'));
        $this->assertSame(135000, $r->total);
        $svc->refund($r, PaymentMethod::Card, 135000);

        $sale = $this->sale->fresh();
        $this->assertSame('full', $sale->return_status);
        $this->assertSame(SaleStatus::Refunded, $sale->status);
        $this->assertSame(10, $this->qty($this->branch, $this->frame));
    }

    public function test_partial_return_credit_is_pro_rata_with_discounts(): void
    {
        $sale = app(SaleService::class)->complete([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'discount' => 8000, // spread over 3 frames = 90000 - 8000 = 82000 (within the 10% cashier limit)
            'items' => [['product_id' => $this->frame->id, 'quantity' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 82000]],
        ]);
        $item = $sale->items->first();
        $svc = app(ReturnService::class);
        $r1 = $svc->create($sale, [['sale_item_id' => $item->id, 'quantity' => 1, 'condition' => 'good', 'disposition' => 'restock']], 'x');
        $r2 = $svc->create($sale->fresh(), [['sale_item_id' => $item->id, 'quantity' => 2, 'condition' => 'good', 'disposition' => 'restock']], 'y');

        $this->assertSame(27333, $r1->total); // 82000 / 3 = 27333.33
        $this->assertSame(82000 - 27333, $r2->total, 'Last units absorb rounding so totals match exactly');
    }

    public function test_exchange_uses_return_credit_in_a_new_sale(): void
    {
        $customer = Customer::factory()->create();
        $sale = app(SaleService::class)->complete([
            'uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $this->sunglasses->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 45000]],
        ]);
        $svc = app(ReturnService::class);
        $item = $sale->items->first();
        $r = $svc->complete($svc->create($sale, [['sale_item_id' => $item->id, 'quantity' => 1, 'condition' => 'good', 'disposition' => 'restock']], 'Exchange for frame', 'exchange'));

        // New sale: 1 frame (30000) fully covered by the 45000 credit.
        $new = app(SaleService::class)->complete([
            'uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $this->frame->id, 'quantity' => 1]],
            'payments' => [['method' => 'store_credit', 'amount' => 30000, 'sale_return_id' => $r->id]],
        ]);

        $this->assertSame(SaleStatus::Completed, $new->status);
        $r->refresh();
        $this->assertSame(30000, $r->exchange_credit_used);
        $this->assertSame($new->id, $r->exchange_sale_id);
        $this->assertSame(15000, $sale->fresh()->refundable(), 'Remaining credit can still be refunded');
        $this->assertSame(-15000, app(CustomerLedger::class)->totals($customer)['balance'], 'Business owes the customer 150.00');
    }

    public function test_cashier_cannot_approve_returns_or_refund(): void
    {
        $this->actingAsRole('cashier', [$this->branch]);
        $svc = app(ReturnService::class);
        $r = $svc->create($this->sale, [$this->returnLine($this->frame, 1)], 'Customer request');

        try {
            $svc->complete($r);
            $this->fail('Cashier approved a return');
        } catch (AuthorizationException) {
        }
        $this->expectException(AuthorizationException::class);
        $svc->refund($r, PaymentMethod::Cash, 100);
    }
}
