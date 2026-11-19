<?php

namespace Tests\Feature\Sales;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Purchasing\Services\PurchaseService;
use App\Modules\Sales\Livewire\Pos;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\ReturnService;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PosAndPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_completes_a_sale_from_the_pos_screen(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['selling_price' => 25000, 'barcode' => '2900000000017']);
        $this->stock($branch, $product, 3);
        $this->actingAsRole('cashier', [$branch]);

        Livewire::test(Pos::class)
            ->set('scan', '2900000000017')->call('scanCode')
            ->set('scan', '2900000000017')->call('scanCode')
            ->assertSet('cart.'.$product->id.'.qty', 2)
            ->call('addPayment', 'cash')
            ->assertSet('payments.0.amount', '500.00')
            ->set('payments.0.tendered', '600')
            ->assertSee(money(10000))
            ->call('complete')
            ->assertHasNoErrors()
            ->assertSet('cart', []);

        $sale = Sale::sole();
        $this->assertSame(50000, $sale->total);
        $this->assertSame(1, $this->qty($branch, $product));
    }

    public function test_pos_rejects_unknown_codes_and_holds_drafts(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->stock($branch, $product, 3);
        $this->actingAsRole('cashier', [$branch]);

        Livewire::test(Pos::class)
            ->set('scan', 'NOPE')->call('scanCode')->assertHasErrors('scan')
            ->call('addProduct', $product->id)
            ->call('hold');

        $draft = Sale::sole();
        $this->assertSame('draft', $draft->status->value);
        $this->assertSame(3, $this->qty($branch, $product), 'Drafts do not move stock');
        $this->assertNull($draft->invoice_number);
    }

    public function test_all_phase_two_pages_and_documents_render(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $this->stock($branch, $product, 10);
        $this->actingAsRole('admin', [$branch]);

        $sale = app(SaleService::class)->complete([
            'uuid' => (string) Str::uuid(), 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ]);
        $return = app(ReturnService::class)->create($sale, [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1, 'condition' => 'good', 'disposition' => 'restock']], 'Test');
        $po = app(PurchaseService::class)->saveDraft([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'order_date' => today()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1000]],
        ]);
        $po = app(PurchaseService::class)->confirm($po);
        app(PaymentService::class)->paySupplier($supplier, $branch, PaymentMethod::Cash, [$po->id => 500]);

        $pages = [
            route('dashboard'), route('pos'), route('sales.dashboard'), route('sales.index'), route('sales.show', $sale),
            route('returns.index'), route('returns.create', ['invoice' => $sale->invoice_number]), route('returns.show', $return),
            route('customers.index'), route('customers.show', $customer), route('customers.show', [$customer, 'tab' => 'statement']),
            route('suppliers.index'), route('suppliers.show', $supplier),
            route('purchases.index'), route('purchases.create'), route('purchases.show', $po), route('payments.index'),
            route('sales.receipt', $sale), route('sales.invoice', $sale),
            route('customers.statement', $customer), route('suppliers.statement', $supplier),
            route('settings.index'), route('audit.index'), route('users.index'), route('branches.index'), route('branches.show', $branch),
            route('categories.index'), route('brands.index'), route('inventory.index'), route('inventory.operations'), route('inventory.movements'),
            route('transfers.index'), route('transfers.create'), route('products.index'), route('products.create'),
        ];
        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }

        $pdf = $this->get(route('sales.pdf', $sale));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get(route('purchases.pdf', $po))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // English locale renders LTR.
        $this->get(route('locale', 'en'));
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('dir="ltr"', false);
    }
}
