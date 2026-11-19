<?php

namespace Tests\Feature\Offline;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\Settings;
use App\Modules\Offline\Models\PosDevice;
use App\Modules\Offline\Models\SyncConflict;
use App\Modules\Offline\Services\OfflineSyncService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Product $product;

    private string $cookie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->product = Product::factory()->create(['selling_price' => 20000]);
        $this->stock($this->branch, $this->product, 2);
        $this->actingAsRole('cashier', [$this->branch]);
        $res = $this->postJson(route('pos.offline.register'), ['name' => 'Counter 1', 'branch_id' => $this->branch->id])->assertOk();
        $cookie = $res->getCookie(OfflineSyncService::COOKIE);
        $this->assertTrue($cookie->isHttpOnly(), 'Device secret must not be readable by scripts');
        $this->assertSame('strict', strtolower((string) $cookie->getSameSite()));
        $this->cookie = $cookie->getValue();
    }

    private function sale(array $overrides = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'created_at' => now()->subHour()->toIso8601String(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 20000]],
            'payments' => [['method' => 'cash', 'amount' => 20000, 'tendered' => 20000]],
        ], $overrides);
    }

    private function sync(array $sales)
    {
        return $this->withCredentials()->withCookie(OfflineSyncService::COOKIE, $this->cookie)->postJson(route('pos.offline.sync'), ['sales' => $sales]);
    }

    public function test_bootstrap_gives_branch_snapshot_and_requires_a_registered_device(): void
    {
        $data = $this->withCredentials()->withCookie(OfflineSyncService::COOKIE, $this->cookie)->getJson(route('pos.offline.bootstrap'))->assertOk()->json();
        $p = collect($data['products'])->firstWhere('id', $this->product->id);
        $this->assertSame(2, $p['stock']);
        $this->assertSame(20000, $p['price']);
        $this->assertSame($this->branch->id, $data['device']['branch_id']);
        $this->assertArrayNotHasKey('token_hash', $data['device']);

        $this->defaultCookies = [];
        $this->getJson(route('pos.offline.bootstrap'))->assertForbidden(); // no device cookie
        $this->withCredentials()->withCookie(OfflineSyncService::COOKIE, 'fake|secret')->getJson(route('pos.offline.bootstrap'))->assertForbidden();
    }

    public function test_offline_sale_syncs_once_and_replays_are_idempotent(): void
    {
        app(Settings::class)->set('cash_shift_required', true); // offline sales are never blocked by a missing shift
        $sale = $this->sale();

        $r1 = $this->sync([$sale])->assertOk()->json('results.0');
        $this->assertSame('synced', $r1['status']);
        $r2 = $this->sync([$sale])->assertOk()->json('results.0');
        $this->assertSame('synced', $r2['status']);
        $this->assertSame($r1['invoice_number'], $r2['invoice_number']);

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Payment::count());
        $s = Sale::sole();
        $this->assertSame('offline', $s->channel);
        $this->assertSame(PosDevice::sole()->id, $s->device_id);
        $this->assertSame(now()->subHour()->format('Y-m-d H'), $s->sold_at->format('Y-m-d H'), 'Original offline time kept');
        $this->assertSame(1, $this->qty($this->branch, $this->product));
        $this->assertDatabaseCount('sync_logs', 2);
    }

    public function test_oversold_offline_sale_is_accepted_and_flagged_for_review(): void
    {
        $r = $this->sync([$this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 20000]],
            'payments' => [['method' => 'card', 'amount' => 60000]]])])->json('results.0');

        $this->assertSame('review', $r['status']);
        $this->assertSame(-1, $this->qty($this->branch, $this->product), 'Recorded truthfully, never silently capped');
        $this->assertTrue(Sale::sole()->needs_review);
        $conflict = SyncConflict::sole();
        $this->assertSame('insufficient_stock', $conflict->type);

        $this->actingAsRole('branch-manager', [$this->branch]);
        app(OfflineSyncService::class)->resolve($conflict, 'Counted stock, adjusted');
        $this->assertFalse(Sale::sole()->needs_review);
    }

    public function test_reject_policy_keeps_oversold_sale_out_with_payload_for_review(): void
    {
        app(Settings::class)->set('offline_oversell_policy', 'reject');
        $r = $this->sync([$this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 20000]],
            'payments' => [['method' => 'card', 'amount' => 100000]]])])->json('results.0');

        $this->assertSame('review', $r['status']);
        $this->assertSame(0, Sale::count());
        $this->assertSame(2, $this->qty($this->branch, $this->product));
        $this->assertNotNull(SyncConflict::sole()->payload);
    }

    public function test_offline_price_is_kept_but_flagged_when_catalog_changed(): void
    {
        $r = $this->sync([$this->sale(['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 18000]],
            'payments' => [['method' => 'cash', 'amount' => 18000]]])])->json('results.0');
        $this->assertSame('review', $r['status']);
        $this->assertSame(18000, Sale::sole()->total);
        $this->assertSame('price_changed', SyncConflict::sole()->type);
    }

    public function test_branch_binding_revocation_and_authentication(): void
    {
        $other = Branch::factory()->create();
        $r = $this->sync([$this->sale(['branch_id' => $other->id])])->json('results.0');
        $this->assertSame('review', $r['status']);
        $this->assertSame(0, Sale::count());

        $this->sync([$this->sale(['created_at' => now()->subDays(30)->toIso8601String()])])->assertOk();
        $this->assertSame(0, Sale::count(), 'Too old for the offline window');

        // A user of another branch cannot use this device.
        $this->actingAsRole('cashier', [$other]);
        $this->sync([$this->sale()])->assertForbidden();

        $this->actingAsRole('admin', [$this->branch]);
        app(OfflineSyncService::class)->revoke(PosDevice::sole());
        $this->actingAsRole('cashier', [$this->branch]);
        $this->sync([$this->sale()])->assertForbidden();

        auth()->logout();
        $this->sync([$this->sale()])->assertUnauthorized();
    }

    public function test_invalid_payload_fails_without_side_effects(): void
    {
        $r = $this->sync([$this->sale(['items' => []])])->json('results.0');
        $this->assertSame('failed', $r['status']);
        $this->assertSame(0, Sale::count());
    }

    public function test_pages_and_pwa_files(): void
    {
        $this->get(route('pos.offline'))->assertOk()->assertSee('offline-pos', false)->assertSee('manifest.webmanifest', false);
        $this->actingAsRole('branch-manager', [$this->branch]);
        foreach (['conflicts', 'devices', 'logs'] as $tab) {
            $this->get(route('offline.review', ['tab' => $tab]))->assertOk();
        }
        $this->actingAsRole('cashier', [$this->branch]);
        $this->get(route('offline.review'))->assertForbidden();
        $this->assertFileExists(public_path('sw.js'));
        $this->assertJson(file_get_contents(public_path('manifest.webmanifest')));
        $this->assertFileExists(public_path('offline.html'));
    }
}
