<?php

namespace Tests\Feature\Ops;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\IntegrityChecker;
use App\Modules\Reports\Services\ReportEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Volume smoke test (test database only): bulk-inserts thousands of rows and checks that the hot
 * queries stay fast. Thresholds are generous for SQLite on a laptop; MySQL with the same indexes is faster.
 */
#[Group('performance')]
class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function time(callable $fn): float
    {
        $t = hrtime(true);
        $fn();

        return (hrtime(true) - $t) / 1e6;
    }

    public function test_hot_queries_stay_fast_with_volume_data(): void
    {
        $branches = Branch::factory()->count(3)->create();
        $now = now()->toDateTimeString();
        $user = $this->actingAsSuperAdmin();

        // 5,000 products, stock rows for 3 branches.
        $rows = [];
        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = ['name' => "Frame model $i", 'sku' => "SKU-$i", 'barcode' => (string) (2900000000000 + $i), 'product_type' => 'frame',
                'selling_price' => 10000 + $i, 'purchase_price' => 5000, 'cost_price' => 5000, 'track_stock' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            if (count($rows) === 500) {
                DB::table('products')->insert($rows);
                $rows = [];
            }
        }
        $ids = DB::table('products')->pluck('id');
        foreach ($branches as $b) {
            foreach ($ids->chunk(500) as $chunk) {
                DB::table('inventory')->insert($chunk->map(fn ($id) => ['branch_id' => $b->id, 'product_id' => $id, 'quantity' => 10, 'reserved_quantity' => 0, 'created_at' => $now, 'updated_at' => $now])->values()->all());
            }
        }

        // 20,000 sale lines over 10,000 invoices in the last 90 days.
        $sales = [];
        for ($i = 1; $i <= 10000; $i++) {
            $sales[] = ['uuid' => (string) Str::uuid(), 'invoice_number' => "INV-P-$i", 'branch_id' => $branches[$i % 3]->id, 'cashier_id' => $user->id, 'status' => 'completed',
                'payment_status' => 'paid', 'subtotal' => 20000, 'total' => 20000, 'paid_amount' => 20000, 'sold_at' => now()->subMinutes($i * 12)->toDateTimeString(),
                'created_at' => $now, 'updated_at' => $now];
            if (count($sales) === 500) {
                DB::table('sales')->insert($sales);
                $sales = [];
            }
        }
        $saleIds = DB::table('sales')->pluck('id');
        $items = [];
        foreach ($saleIds as $k => $sid) {
            foreach ([0, 1] as $j) {
                $pid = $ids[($k * 2 + $j) % $ids->count()];
                $items[] = ['sale_id' => $sid, 'product_id' => $pid, 'product_name' => 'x', 'sku' => 'x', 'quantity' => 1, 'unit_price' => 10000,
                    'line_total' => 10000, 'unit_cost' => 5000, 'created_at' => $now, 'updated_at' => $now];
            }
            if (count($items) >= 1000) {
                DB::table('sale_items')->insert($items);
                $items = [];
            }
        }
        if ($items) {
            DB::table('sale_items')->insert($items);
        }

        $timings = [
            'pos_product_search' => $this->time(fn () => Product::query()->search('model 4321')->limit(20)->get()),
            'barcode_lookup' => $this->time(fn () => Product::where('barcode', '2900000004321')->first()),
            'stock_levels_page' => $this->time(fn () => DB::table('inventory')->where('branch_id', $branches[0]->id)->join('products', 'products.id', '=', 'inventory.product_id')->orderBy('products.name')->limit(50)->get()),
            'sales_by_period_90d' => $this->time(fn () => app(ReportEngine::class)->run('sales_period', $branches->pluck('id')->all(), now()->subDays(90)->toDateString(), now()->toDateString())),
            'top_products_90d' => $this->time(fn () => app(ReportEngine::class)->run('sales_product', $branches->pluck('id')->all(), now()->subDays(90)->toDateString(), now()->toDateString())),
            'integrity_checks' => $this->time(fn () => app(IntegrityChecker::class)->run()),
        ];
        fwrite(STDERR, "\nPerformance (ms): ".json_encode(array_map(fn ($v) => round($v, 1), $timings))."\n");

        $this->assertLessThan(300, $timings['pos_product_search']);
        $this->assertLessThan(50, $timings['barcode_lookup']);
        $this->assertLessThan(300, $timings['stock_levels_page']);
        $this->assertLessThan(3000, $timings['sales_by_period_90d']);
        $this->assertLessThan(3000, $timings['top_products_90d']);
        $this->assertLessThan(5000, $timings['integrity_checks']);
    }
}
