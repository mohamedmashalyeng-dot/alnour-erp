<?php

namespace Tests\Feature\Inventory;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Livewire\StockOperations;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;

    private Branch $b;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Branch::factory()->create();
        $this->b = Branch::factory()->create();
        $this->product = Product::factory()->create();
    }

    private function service(): InventoryService
    {
        return app(InventoryService::class);
    }

    public function test_opening_stock_and_receipt_add_stock_with_transactions(): void
    {
        $this->actingAsRole('branch-manager', [$this->a]);

        $this->service()->recordOpeningStock($this->a->id, [['product_id' => $this->product->id, 'quantity' => 10]]);
        $this->service()->receiveStock($this->a->id, [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 1200]], 'Delivery note 44');

        $this->assertSame(15, $this->qty($this->a, $this->product));
        $tx = InventoryTransaction::orderBy('id')->get();
        $this->assertCount(2, $tx);
        $this->assertSame([MovementType::Opening, MovementType::Receipt], $tx->pluck('type')->all());
        $this->assertSame([0, 10], $tx->pluck('balance_before')->all());
        $this->assertSame([10, 15], $tx->pluck('balance_after')->all());
        $this->assertSame(1200, $tx[1]->unit_cost);
    }

    public function test_opening_stock_can_only_be_recorded_once(): void
    {
        $this->actingAsRole('branch-manager', [$this->a]);
        $this->service()->recordOpeningStock($this->a->id, [['product_id' => $this->product->id, 'quantity' => 3]]);

        $this->expectException(ValidationException::class);
        $this->service()->recordOpeningStock($this->a->id, [['product_id' => $this->product->id, 'quantity' => 3]]);
    }

    public function test_adjustment_deducts_stock_and_requires_a_reason(): void
    {
        $this->stock($this->a, $this->product, 10);
        $this->actingAsRole('branch-manager', [$this->a]);

        $this->service()->adjust($this->a->id, $this->product->id, -4, 'Broken in display');
        $this->assertSame(6, $this->qty($this->a, $this->product));
        $this->assertDatabaseHas('inventory_transactions', ['type' => 'adjustment_out', 'quantity' => -4, 'reason' => 'Broken in display']);

        $this->expectException(ValidationException::class);
        $this->service()->adjust($this->a->id, $this->product->id, -1, '   ');
    }

    public function test_negative_inventory_is_prevented(): void
    {
        $this->stock($this->a, $this->product, 2);
        $this->actingAsRole('branch-manager', [$this->a]);

        try {
            $this->service()->adjust($this->a->id, $this->product->id, -3, 'Count correction');
            $this->fail('Negative stock was allowed');
        } catch (ValidationException) {
        }
        $this->assertSame(2, $this->qty($this->a, $this->product));
        $this->assertSame(1, InventoryTransaction::count(), 'No transaction may be written for a rejected movement');
    }

    public function test_negative_inventory_requires_explicit_authorization(): void
    {
        $this->stock($this->a, $this->product, 1);

        // Branch manager lacks inventory.allow-negative.
        $this->actingAsRole('branch-manager', [$this->a]);
        try {
            $this->service()->adjust($this->a->id, $this->product->id, -2, 'Correction', allowNegative: true);
            $this->fail('Negative override was allowed without permission');
        } catch (AuthorizationException) {
        }

        $this->actingAsRole('branch-manager', [$this->a], ['inventory.allow-negative']);
        $this->service()->adjust($this->a->id, $this->product->id, -2, 'Correction', allowNegative: true);
        $this->assertSame(-1, $this->qty($this->a, $this->product));
    }

    public function test_branch_inventories_are_isolated(): void
    {
        $this->stock($this->a, $this->product, 10);
        $this->stock($this->b, $this->product, 4);
        $this->actingAsRole('branch-manager', [$this->a]);

        $this->service()->adjust($this->a->id, $this->product->id, -3, 'Sold offline');

        $this->assertSame(7, $this->qty($this->a, $this->product));
        $this->assertSame(4, $this->qty($this->b, $this->product), 'Other branch must not be affected');
    }

    public function test_user_cannot_change_stock_of_an_unassigned_branch(): void
    {
        $this->stock($this->b, $this->product, 5);
        $this->actingAsRole('branch-manager', [$this->a]);

        $this->expectException(AuthorizationException::class);
        $this->service()->adjust($this->b->id, $this->product->id, -1, 'Not my branch');
    }

    public function test_user_cannot_view_stock_of_an_unassigned_branch(): void
    {
        $this->actingAsRole('branch-manager', [$this->a]);

        $this->get(route('inventory.index', ['branch' => $this->a->id]))->assertOk();
        $this->get(route('inventory.index', ['branch' => $this->b->id]))->assertForbidden();
        $this->get(route('inventory.index', ['branch' => 0]))->assertForbidden(); // combined view needs access-all
    }

    public function test_combined_inventory_view_for_users_with_all_branch_access(): void
    {
        $this->stock($this->a, $this->product, 3);
        $this->stock($this->b, $this->product, 4);
        $this->actingAsRole('admin');

        $this->get(route('inventory.index', ['branch' => 0]))->assertOk()->assertSee($this->product->sku);
    }

    public function test_cashier_cannot_adjust_stock(): void
    {
        $this->stock($this->a, $this->product, 5);
        $this->actingAsRole('cashier', [$this->a]);

        $this->get(route('inventory.operations'))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->service()->adjust($this->a->id, $this->product->id, -1, 'x');
    }

    public function test_stock_operations_page_receives_stock(): void
    {
        $this->actingAsRole('branch-manager', [$this->a]);

        Livewire::test(StockOperations::class)
            ->set('mode', 'receive')
            ->set('branchId', $this->a->id)
            ->set('scan', $this->product->barcode)
            ->call('scanCode')
            ->set('lines.0.quantity', '7')
            ->set('reason', 'Supplier delivery')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(7, $this->qty($this->a, $this->product));
    }

    public function test_inventory_quantity_always_equals_the_sum_of_its_transactions(): void
    {
        $this->actingAsRole('admin');
        $this->service()->recordOpeningStock($this->a->id, [['product_id' => $this->product->id, 'quantity' => 20]]);
        $this->service()->receiveStock($this->a->id, [['product_id' => $this->product->id, 'quantity' => 5]], 'Receipt');
        $this->service()->adjust($this->a->id, $this->product->id, -7, 'Damage');
        $this->service()->adjust($this->a->id, $this->product->id, 2, 'Found');

        $sum = (int) InventoryTransaction::where('branch_id', $this->a->id)->where('product_id', $this->product->id)->sum('quantity');
        $this->assertSame($sum, $this->qty($this->a, $this->product));

        // Each entry's balance_before equals the previous entry's balance_after (unbroken chain).
        $previous = 0;
        foreach (InventoryTransaction::orderBy('id')->get() as $tx) {
            $this->assertSame($previous, $tx->balance_before);
            $this->assertSame($tx->balance_before + $tx->quantity, $tx->balance_after);
            $previous = $tx->balance_after;
        }
    }

    public function test_inventory_transactions_are_immutable(): void
    {
        $this->stock($this->a, $this->product, 1);
        $tx = InventoryTransaction::firstOrFail();

        $this->expectException(LogicException::class);
        $tx->update(['quantity' => 100]);
    }

    public function test_inventory_quantity_is_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);
        Inventory::create(['branch_id' => $this->a->id, 'product_id' => $this->product->id, 'quantity' => 50]);
    }

    public function test_low_stock_alerts(): void
    {
        $this->stock($this->a, $this->product, 2);
        $this->actingAsRole('branch-manager', [$this->a]);
        $this->service()->setMinStockLevel($this->a->id, $this->product->id, 3);

        $this->assertSame(1, Inventory::lowStock()->count());
        $this->get(route('inventory.index', ['branch' => $this->a->id, 'lowOnly' => 1]))->assertOk()->assertSee($this->product->sku);
    }
}
