<?php

namespace Tests\Feature\Inventory;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockTransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private Branch $from;

    private Branch $to;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->from = Branch::factory()->create();
        $this->to = Branch::factory()->create();
        $this->product = Product::factory()->create();
        $this->stock($this->from, $this->product, 10);
    }

    private function svc(): StockTransferService
    {
        return app(StockTransferService::class);
    }

    public function test_full_transfer_lifecycle_moves_stock_exactly_once(): void
    {
        $this->actingAsRole('branch-manager', [$this->from, $this->to]);

        $t = $this->svc()->create($this->from->id, $this->to->id, [['product_id' => $this->product->id, 'quantity' => 4]]);
        $this->assertSame(TransferStatus::Pending, $t->status);
        $this->assertSame(10, $this->qty($this->from, $this->product));

        $t = $this->svc()->approve($t);
        $this->assertSame(TransferStatus::Approved, $t->status);
        $row = Inventory::where('branch_id', $this->from->id)->first();
        $this->assertSame(4, $row->reserved_quantity);
        $this->assertSame(6, $row->available());

        $t = $this->svc()->dispatch($t);
        $this->assertSame(TransferStatus::Dispatched, $t->status);
        $this->assertSame(6, $this->qty($this->from, $this->product));
        $this->assertSame(0, Inventory::where('branch_id', $this->from->id)->value('reserved_quantity'));
        $this->assertSame(0, $this->qty($this->to, $this->product), 'In transit: not yet at destination');

        $t = $this->svc()->receive($t);
        $this->assertSame(TransferStatus::Received, $t->status);
        $this->assertSame(4, $this->qty($this->to, $this->product));

        $this->assertDatabaseHas('inventory_transactions', ['branch_id' => $this->from->id, 'type' => 'transfer_out', 'quantity' => -4]);
        $this->assertDatabaseHas('inventory_transactions', ['branch_id' => $this->to->id, 'type' => 'transfer_in', 'quantity' => 4]);

        // Replaying transitions must fail and must not move stock again.
        foreach (['dispatch', 'receive', 'approve'] as $step) {
            try {
                $this->svc()->{$step}($t);
                $this->fail("$step was allowed twice");
            } catch (ValidationException) {
            }
        }
        $this->assertSame(6, $this->qty($this->from, $this->product));
        $this->assertSame(4, $this->qty($this->to, $this->product));
        $this->assertSame(3, InventoryTransaction::count()); // opening + out + in
    }

    public function test_cannot_skip_approval(): void
    {
        $this->actingAsRole('branch-manager', [$this->from, $this->to]);
        $t = $this->svc()->create($this->from->id, $this->to->id, [['product_id' => $this->product->id, 'quantity' => 1]]);

        $this->expectException(ValidationException::class);
        $this->svc()->dispatch($t);
    }

    public function test_cancel_releases_reservation_and_dispatched_transfers_cannot_be_cancelled(): void
    {
        $this->actingAsRole('branch-manager', [$this->from, $this->to]);
        $t = $this->svc()->approve($this->svc()->create($this->from->id, $this->to->id, [['product_id' => $this->product->id, 'quantity' => 5]]));
        $this->svc()->cancel($t, 'Customer changed mind');
        $this->assertSame(0, Inventory::where('branch_id', $this->from->id)->value('reserved_quantity'));
        $this->assertSame(10, $this->qty($this->from, $this->product));

        $t2 = $this->svc()->dispatch($this->svc()->approve($this->svc()->create($this->from->id, $this->to->id, [['product_id' => $this->product->id, 'quantity' => 2]])));
        $this->expectException(ValidationException::class);
        $this->svc()->cancel($t2, 'Too late');
    }

    public function test_cannot_approve_more_than_available_stock(): void
    {
        $this->actingAsRole('branch-manager', [$this->from, $this->to]);
        $t = $this->svc()->create($this->from->id, $this->to->id, [['product_id' => $this->product->id, 'quantity' => 11]]);

        $this->expectException(ValidationException::class);
        $this->svc()->approve($t);
    }

    public function test_reserved_stock_cannot_be_adjusted_away(): void
    {
        $this->actingAsRole('branch-manager', [$this->from, $this->to]);
        $this->svc()->approve($this->svc()->create($this->from->id, $this->to->id, [['product_id' => $this->product->id, 'quantity' => 8]]));

        $this->expectException(ValidationException::class);
        app(InventoryService::class)->adjust($this->from->id, $this->product->id, -3, 'Shrinkage'); // only 2 available
    }

    public function test_only_destination_users_can_receive(): void
    {
        $this->actingAsRole('branch-manager', [$this->from, $this->to]);
        $t = $this->svc()->dispatch($this->svc()->approve($this->svc()->create($this->from->id, $this->to->id, [['product_id' => $this->product->id, 'quantity' => 1]])));

        $this->actingAsRole('branch-manager', [$this->from]);
        $this->expectException(AuthorizationException::class);
        $this->svc()->receive($t);
    }

    public function test_transfer_pages_are_branch_scoped(): void
    {
        $this->actingAsRole('branch-manager', [$this->from, $this->to]);
        $t = $this->svc()->create($this->from->id, $this->to->id, [['product_id' => $this->product->id, 'quantity' => 1]]);
        $this->get(route('transfers.show', $t))->assertOk()->assertSee($t->number);

        $other = Branch::factory()->create();
        $this->actingAsRole('branch-manager', [$other]);
        $this->get(route('transfers.show', $t))->assertForbidden();
    }
}
