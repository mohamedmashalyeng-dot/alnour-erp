<?php

namespace Tests\Feature\Optical;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\Settings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Optical\Models\LoyaltyTransaction;
use App\Modules\Optical\Models\Prescription;
use App\Modules\Optical\Models\RepairOrder;
use App\Modules\Optical\Models\Warranty;
use App\Modules\Optical\Services\LoyaltyService;
use App\Modules\Optical\Services\PrescriptionService;
use App\Modules\Optical\Services\RepairService;
use App\Modules\Optical\Services\WarrantyService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\ReturnService;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class OpticalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->customer = Customer::factory()->create();
    }

    private function rxInput(array $over = []): array
    {
        return array_merge(['branch_id' => $this->branch->id, 'type' => 'spectacles', 'examined_on' => today()->toDateString(), 'expires_on' => today()->addYear()->toDateString(),
            'od_sph' => '-2.25', 'od_cyl' => '-0.75', 'od_axis' => '180', 'od_add' => '', 'os_sph' => '-2.00', 'os_cyl' => '', 'os_axis' => '', 'os_add' => '',
            'pd' => '63.5', 'medical_notes' => 'Mild dry eye'], $over);
    }

    private function prescription(array $over = []): Prescription
    {
        $this->actingAsRole('optometrist', [$this->branch]);

        return app(PrescriptionService::class)->create($this->customer, $this->rxInput($over));
    }

    private function sell(array $items, array $extra = []): Sale
    {
        return app(SaleService::class)->complete(array_merge([
            'uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'items' => $items, 'payments' => [],
        ], $extra));
    }

    // ------------------------------------------------------------ prescriptions

    public function test_prescription_values_are_validated_and_stored_as_integers(): void
    {
        $rx = $this->prescription();
        $this->assertSame(-225, $rx->od_sph);
        $this->assertSame(-75, $rx->od_cyl);
        $this->assertSame(180, $rx->od_axis);
        $this->assertSame(635, $rx->pd);
        $this->assertStringStartsWith('RX-', $rx->number);
        $this->assertStringNotContainsString('dry eye', DB::table('prescriptions')->value('medical_notes'), 'Medical notes encrypted');

        $cases = [
            ['od_sph' => '-2.30'],                       // not a 0.25 step
            ['od_cyl' => '-1.00', 'od_axis' => ''],      // cylinder without axis
            ['od_axis' => '200'],                        // axis out of range
            ['os_sph' => '-35.00'],                      // sphere out of range
            ['od_add' => '5.00'],                        // addition out of range
            ['pd' => ''],                                // PD required for spectacles
            ['os_cyl' => '', 'os_axis' => '90'],         // axis without cylinder
            ['expires_on' => today()->subDay()->toDateString()],
        ];
        foreach ($cases as $i => $bad) {
            try {
                app(PrescriptionService::class)->create($this->customer, $this->rxInput($bad));
                $this->fail("Case $i should be rejected");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        // Arabic digits are accepted.
        $ar = app(PrescriptionService::class)->create($this->customer, $this->rxInput(['od_sph' => '-١٫٥٠', 'od_cyl' => '', 'od_axis' => '']));
        $this->assertSame(-150, $ar->od_sph);
    }

    public function test_prescription_access_is_restricted_and_records_are_voided_not_edited(): void
    {
        Storage::fake('local');
        $rx = $this->prescription();
        $att = app(PrescriptionService::class)->attach($rx, UploadedFile::fake()->create('rx.pdf', 20, 'application/pdf'));
        $this->get(route('attachments.show', $att))->assertOk();
        $this->get(route('optical.prescriptions.show', $rx))->assertOk()->assertSee('-2.25');

        $this->actingAsRole('inventory-clerk', [$this->branch]);
        $this->get(route('optical.prescriptions'))->assertForbidden();
        $this->get(route('attachments.show', $att))->assertForbidden();

        $this->actingAsRole('cashier', [$this->branch]);
        $this->get(route('optical.prescriptions.show', $rx))->assertOk();
        try {
            app(PrescriptionService::class)->create($this->customer, $this->rxInput());
            $this->fail('Cashier cannot write prescriptions');
        } catch (AuthorizationException) {
        }

        $this->actingAsRole('optometrist', [$this->branch]);
        app(PrescriptionService::class)->void($rx, 'Re-examined');
        $this->assertSame('void', $rx->refresh()->status);
        $this->assertFalse($rx->isUsable());
    }

    public function test_sale_links_prescription_and_rejects_incompatible_lenses(): void
    {
        $rx = $this->prescription(['od_sph' => '-8.00', 'od_cyl' => '', 'od_axis' => '']);
        $lens = Product::factory()->create(['product_type' => 'ophthalmic_lens', 'track_stock' => false, 'selling_price' => 40000,
            'specifications' => ['sph_min' => '-6.00', 'sph_max' => '+6.00', 'cyl_min' => '-2.00', 'cyl_max' => '0']]);
        $this->actingAsRole('cashier', [$this->branch]);

        try {
            $this->sell([['product_id' => $lens->id, 'quantity' => 1]], ['prescription_id' => $rx->id, 'payments' => [['method' => 'cash', 'amount' => 40000]]]);
            $this->fail('Lens range must be enforced');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('prescription_id', $e->errors());
        }

        $ok = $this->prescription(['od_sph' => '-4.00']);
        $other = Prescription::create(['number' => 'RX-X', 'customer_id' => Customer::factory()->create()->id, 'branch_id' => $this->branch->id, 'type' => 'spectacles',
            'examined_on' => today()->toDateString(), 'expires_on' => today()->addYear()->toDateString(), 'od_sph' => 0, 'os_sph' => 0, 'pd' => 630, 'status' => 'active']);
        $this->actingAsRole('cashier', [$this->branch]);
        try {
            $this->sell([['product_id' => $lens->id, 'quantity' => 1]], ['prescription_id' => $other->id, 'payments' => [['method' => 'cash', 'amount' => 40000]]]);
            $this->fail('Prescription of another customer');
        } catch (ValidationException) {
        }
        $sale = $this->sell([['product_id' => $lens->id, 'quantity' => 1]], ['prescription_id' => $ok->id, 'payments' => [['method' => 'cash', 'amount' => 40000]]]);
        $this->assertSame($ok->id, $sale->prescription_id);
    }

    // ---------------------------------------------------------------- warranty

    public function test_warranty_lifecycle_and_claims(): void
    {
        $frame = Product::factory()->create(['selling_price' => 50000, 'warranty_months' => 12]);
        $plain = Product::factory()->create(['selling_price' => 1000]);
        $this->stock($this->branch, $frame, 5);
        $this->stock($this->branch, $plain, 5);
        $cashier = $this->actingAsRole('cashier', [$this->branch]);
        $sale = $this->sell([['product_id' => $frame->id, 'quantity' => 2], ['product_id' => $plain->id, 'quantity' => 1]], ['payments' => [['method' => 'cash', 'amount' => 101000]]]);

        $w = Warranty::sole();
        $this->assertSame(2, $w->quantity);
        $this->assertSame(today()->addMonthsNoOverflow(12)->subDay()->toDateString(), $w->ends_on->toDateString());

        $claim = app(WarrantyService::class)->openClaim($w, 'Hinge broken');
        try {
            app(WarrantyService::class)->openClaim($w, 'Again');
            $this->fail('One open claim per warranty');
        } catch (ValidationException) {
        }
        try {
            app(WarrantyService::class)->decide($claim, true, 'repair', null);
            $this->fail('Cashier cannot decide');
        } catch (AuthorizationException) {
        }
        $this->actingAsRole('branch-manager', [$this->branch]);
        app(WarrantyService::class)->decide($claim, true, 'repair', 'Covered');
        $this->assertSame('approved', $claim->refresh()->status);

        // A return of one unit reduces the warranty; returning the other voids it.
        $this->actingAsRole('branch-manager', [$this->branch]);
        $item = $sale->items()->where('product_id', $frame->id)->first();
        $svc = app(ReturnService::class);
        $svc->complete($svc->create($sale, [['sale_item_id' => $item->id, 'quantity' => 1, 'condition' => 'good', 'disposition' => 'restock']], 'Too big'));
        $this->assertSame(1, $w->refresh()->quantity);
        $svc->complete($svc->create($sale, [['sale_item_id' => $item->id, 'quantity' => 1, 'condition' => 'good', 'disposition' => 'restock']], 'Too big'));
        $this->assertSame('void', $w->refresh()->status);

        // Cancellation voids warranties; expired warranties refuse claims.
        $this->actingAsRole('branch-manager', [$this->branch]);
        $sale2 = $this->sell([['product_id' => $frame->id, 'quantity' => 1]], ['payments' => [['method' => 'cash', 'amount' => 50000]]]);
        $w2 = Warranty::where('sale_id', $sale2->id)->sole();
        $w2->update(['ends_on' => today()->subDay()->toDateString()]);
        try {
            app(WarrantyService::class)->openClaim($w2, 'Scratch');
            $this->fail('Expired warranty');
        } catch (ValidationException) {
        }
        app(SaleService::class)->cancel($sale2, 'Customer changed mind');
        $this->assertSame('void', $w2->refresh()->status);
    }

    // ------------------------------------------------------------------ repairs

    public function test_repair_workflow_and_charged_delivery_creates_invoice(): void
    {
        $service = Product::factory()->create(['product_type' => 'service', 'track_stock' => false, 'selling_price' => 0, 'name' => 'Repair service']);
        app(Settings::class)->set('repair_service_product_id', $service->id);
        $this->actingAsRole('cashier', [$this->branch]);
        $svc = app(RepairService::class);
        $order = $svc->create(['branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'item_description' => 'Metal frame',
            'problem' => 'Loose screw and nose pad', 'estimated_cost' => 3000]);
        $this->assertSame('received', $order->status);

        try {
            $svc->deliver($order, 3000);
            $this->fail('Must be ready first');
        } catch (ValidationException) {
        }
        $svc->setStatus($order, 'in_progress');
        try {
            $svc->setStatus($order->refresh(), 'received');
            $this->fail('Backwards transition');
        } catch (ValidationException) {
        }
        $svc->setStatus($order->refresh(), 'ready');
        $done = $svc->deliver($order->refresh(), 3500, [['method' => 'cash', 'amount' => 3500]]);

        $this->assertSame('delivered', $done->status);
        $sale = Sale::findOrFail($done->sale_id);
        $this->assertSame(3500, $sale->total);
        $this->assertSame($this->customer->id, $sale->customer_id);
        $this->assertSame('paid', $sale->payment_status->value);
        $this->assertSame(1, RepairOrder::count());
    }

    // ------------------------------------------------------------------ loyalty

    public function test_loyalty_earn_redeem_cancel_and_return(): void
    {
        app(Settings::class)->set('loyalty_enabled', true);
        app(Settings::class)->set('loyalty_silver_threshold', 100000); // keep bronze (×1) for exact arithmetic; tiers tested below
        app(Settings::class)->set('loyalty_gold_threshold', 200000);
        $p = Product::factory()->create(['selling_price' => 100000]);
        $this->stock($this->branch, $p, 10);
        $this->actingAsRole('cashier', [$this->branch]);

        $uuid = (string) Str::uuid();
        $data = ['uuid' => $uuid, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'items' => [['product_id' => $p->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 100000]]];
        $sale = app(SaleService::class)->complete($data);
        app(SaleService::class)->complete($data); // replay: no double points
        $this->assertSame(1000, $sale->refresh()->loyalty_points_earned);
        $this->assertSame(1000, $this->customer->refresh()->loyalty_points);

        // Redeem 400 points = 20.00 discount; cashier without discount limits is fine (not a cashier discount).
        $sale2 = $this->sell([['product_id' => $p->id, 'quantity' => 1]], ['redeem_points' => 400, 'payments' => [['method' => 'cash', 'amount' => 98000]]]);
        $this->assertSame(98000, $sale2->total);
        $this->assertSame(2000, $sale2->loyalty_discount);
        $this->assertSame(1000 - 400 + 980, $this->customer->refresh()->loyalty_points);

        foreach ([['redeem_points' => 50], ['redeem_points' => 999999], ['redeem_points' => 1500, 'items' => [['product_id' => $p->id, 'quantity' => 1]]]] as $i => $bad) {
            // below minimum / above balance / above 50% of a 1000.00 invoice? (1500 pts = 75.00 < 500.00, so use a cheaper item)
            if ($i === 2) {
                $cheap = Product::factory()->create(['selling_price' => 10000]);
                $this->stock($this->branch, $cheap, 1);
                $this->actingAsRole('cashier', [$this->branch]);
                $bad['items'] = [['product_id' => $cheap->id, 'quantity' => 1]];
            }
            try {
                $this->sell($bad['items'] ?? [['product_id' => $p->id, 'quantity' => 1]], ['redeem_points' => $bad['redeem_points'], 'payments' => []]);
                $this->fail("Redemption case $i must fail");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('redeem_points', $e->errors());
            }
        }

        // Cancelling sale2 restores the 400 redeemed points and reverses the 980 earned.
        $this->actingAsRole('branch-manager', [$this->branch]);
        app(SaleService::class)->cancel($sale2, 'Test');
        $this->assertSame(1000, $this->customer->refresh()->loyalty_points);

        // Returning the first sale reverses its points pro rata (full return → all 1000).
        $svc = app(ReturnService::class);
        $svc->complete($svc->create($sale, [['sale_item_id' => $sale->items()->first()->id, 'quantity' => 1, 'condition' => 'good', 'disposition' => 'restock']], 'Return'));
        $this->assertSame(0, $this->customer->refresh()->loyalty_points);

        $this->assertSame($this->customer->loyalty_points, (int) LoyaltyTransaction::where('customer_id', $this->customer->id)->sum('points'), 'Ledger = balance');
        $this->expectException(LogicException::class);
        LoyaltyTransaction::first()->delete();
    }

    public function test_tiers_multiply_earned_points(): void
    {
        app(Settings::class)->set('loyalty_enabled', true);
        $loyalty = app(LoyaltyService::class);
        $this->assertSame('bronze', $loyalty->tier($this->customer)['name']);
        $this->customer->forceFill(['loyalty_lifetime_points' => 1000])->save();
        $this->assertSame('silver', $loyalty->tier($this->customer)['name']);
        $p = Product::factory()->create(['selling_price' => 100000]);
        $this->stock($this->branch, $p, 1);
        $this->actingAsRole('cashier', [$this->branch]);
        $sale = $this->sell([['product_id' => $p->id, 'quantity' => 1]], ['payments' => [['method' => 'cash', 'amount' => 100000]]]);
        $this->assertSame(1250, $sale->refresh()->loyalty_points_earned);
        $this->customer->forceFill(['loyalty_lifetime_points' => 5000])->save();
        $this->assertSame('gold', $loyalty->tier($this->customer)['name']);
    }

    public function test_walk_in_and_disabled_program_earn_nothing(): void
    {
        $p = Product::factory()->create(['selling_price' => 100000]);
        $this->stock($this->branch, $p, 2);
        $this->actingAsRole('cashier', [$this->branch]);
        $this->sell([['product_id' => $p->id, 'quantity' => 1]], ['payments' => [['method' => 'cash', 'amount' => 100000]]]);
        $this->assertSame(0, $this->customer->refresh()->loyalty_points, 'Program disabled by default');
        try {
            $this->sell([['product_id' => $p->id, 'quantity' => 1]], ['redeem_points' => 100, 'payments' => []]);
            $this->fail('Disabled program');
        } catch (ValidationException) {
        }
    }

    public function test_optical_pages_render(): void
    {
        $rx = $this->prescription();
        $this->actingAsRole('admin', [$this->branch]);
        $this->get(route('optical.prescriptions'))->assertOk()->assertSee($rx->number);
        $this->get(route('optical.prescriptions.show', $rx))->assertOk();
        $this->get(route('optical.warranties'))->assertOk();
        $this->get(route('optical.warranties', ['tab' => 'claims']))->assertOk();
        $this->get(route('optical.repairs'))->assertOk();
        $this->get(route('optical.loyalty', ['customer' => $this->customer->id]))->assertOk()->assertSee($this->customer->name);
        $this->get(route('settings.index'))->assertOk();

        $this->actingAsRole('inventory-clerk', [$this->branch]);
        foreach (['optical.prescriptions', 'optical.warranties', 'optical.repairs', 'optical.loyalty'] as $r) {
            $this->get(route($r))->assertForbidden();
        }
    }
}
