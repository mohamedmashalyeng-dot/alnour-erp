<?php

namespace Tests\Feature\Reports;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Reports\Livewire\BusinessInsights;
use App\Modules\Reports\Services\AiNarrator;
use App\Modules\Reports\Services\InsightsEngine;
use App\Modules\Reports\Services\ReportEngine;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\ReturnService;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;

    private Branch $b;

    private Product $frame;

    private Product $sun;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Branch::factory()->create(['code' => 'A1']);
        $this->b = Branch::factory()->create(['code' => 'B1']);
        $cat = Category::factory()->create(['name' => 'إطارات']);
        $this->frame = Product::factory()->create(['selling_price' => 10000, 'category_id' => $cat->id]);
        $this->frame->forceFill(['cost_price' => 4000])->save();
        $this->sun = Product::factory()->create(['selling_price' => 20000]);
        $this->sun->forceFill(['cost_price' => 5000])->save();
        foreach ([$this->a, $this->b] as $br) {
            $this->stock($br, $this->frame, 50);
            $this->stock($br, $this->sun, 50);
        }
        $this->actingAsRole('admin', [$this->a, $this->b]);
    }

    private function sell(Branch $branch, Product $p, int $qty): Sale
    {
        return app(SaleService::class)->complete(['uuid' => (string) Str::uuid(), 'branch_id' => $branch->id,
            'items' => [['product_id' => $p->id, 'quantity' => $qty]], 'payments' => [['method' => 'card', 'amount' => $p->selling_price * $qty]]]);
    }

    public function test_sales_reports_are_net_of_returns_and_vat_with_profit(): void
    {
        $sale = $this->sell($this->a, $this->frame, 3);
        $this->sell($this->b, $this->sun, 1);
        $svc = app(ReturnService::class);
        $svc->complete($svc->create($sale, [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1, 'condition' => 'good', 'disposition' => 'restock']], 'x'));

        $engine = app(ReportEngine::class);
        $today = today()->toDateString();
        $byProduct = collect($engine->run('sales_product', [$this->a->id, $this->b->id], $today, $today)['rows'])->keyBy('key');
        $f = $byProduct[(string) $this->frame->id];
        $this->assertSame(2, $f['qty']);
        $this->assertSame(20000, $f['revenue']);
        $this->assertSame(8000, $f['cost']);
        $this->assertSame(12000, $f['profit']);
        $this->assertSame(6000, $f['margin']); // 60.00% in basis points

        $byBranch = collect($engine->run('sales_branch', [$this->a->id, $this->b->id], $today, $today)['rows'])->keyBy('key');
        $this->assertSame(20000, $byBranch['A1']['revenue']);
        $this->assertSame(20000, $byBranch['B1']['revenue']);

        // Branch isolation: branch A only.
        $onlyA = $engine->run('sales_branch', [$this->a->id], $today, $today);
        $this->assertCount(1, $onlyA['rows']);
        $this->assertSame(20000, $onlyA['totals']['revenue']);

        foreach (array_keys(ReportEngine::REPORTS) as $report) {
            $r = $engine->run($report, [$this->a->id, $this->b->id], today()->subMonth()->toDateString(), $today, ['group' => 'week']);
            $this->assertArrayHasKey('rows', $r, $report);
        }
        $cat = collect($engine->run('sales_category', [$this->a->id, $this->b->id], $today, $today)['rows'])->pluck('label')->all();
        $this->assertContains('إطارات', $cat);
    }

    public function test_period_comparison(): void
    {
        $this->travelTo(now()->subMonth());
        $this->sell($this->a, $this->frame, 1);
        $this->travelBack();
        $this->sell($this->a, $this->frame, 3);

        $lastMonth = [now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()];
        $thisMonth = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
        $cmp = app(ReportEngine::class)->compare('sales_branch', [$this->a->id], $lastMonth, $thisMonth);
        $row = $cmp['rows'][0];
        $this->assertSame(10000, $row['revenue_a']);
        $this->assertSame(30000, $row['revenue_b']);
        $this->assertSame(20000, $row['revenue_diff']);
        $this->assertSame(20000, $row['revenue_pct']); // +200.00%
    }

    public function test_exports_and_formula_injection_protection(): void
    {
        $this->sell($this->a, $this->frame, 1);
        $q = ['report' => 'sales_product', 'from' => today()->toDateString(), 'to' => today()->toDateString(), 'branch' => 0];

        $csv = $this->get(route('reports.export', $q + ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString($this->frame->sku, $csv->streamedContent());
        $xlsx = $this->get(route('reports.export', $q + ['format' => 'xlsx']));
        $xlsx->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->streamedContent()); // zip container
        $pdf = $this->get(route('reports.export', $q + ['format' => 'pdf']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame("'=HYPERLINK(\"x\")", ReportExporter::safe('=HYPERLINK("x")'));
        $this->assertSame("'+1", ReportExporter::safe('+1'));
        $this->assertSame('Normal', ReportExporter::safe('Normal'));
    }

    public function test_branch_limited_user_cannot_export_other_branches(): void
    {
        $this->actingAsRole('branch-manager', [$this->a], ['reports.advanced']);
        $q = ['report' => 'sales_branch', 'format' => 'csv', 'from' => today()->toDateString(), 'to' => today()->toDateString()];
        $this->get(route('reports.export', $q + ['branch' => $this->b->id]))->assertForbidden();
        $this->get(route('reports.export', $q + ['branch' => 0]))->assertForbidden();
        $this->get(route('reports.export', $q + ['branch' => $this->a->id]))->assertOk();
        $this->actingAsRole('cashier', [$this->a]);
        $this->get(route('reports.center'))->assertForbidden();
        $this->get(route('reports.insights'))->assertForbidden();
    }

    public function test_insights_detect_decline_slow_movers_and_suggest_reorders(): void
    {
        $this->travelTo(now()->subDays(45));
        $this->sell($this->a, $this->frame, 10);
        $this->travelBack();
        $this->sell($this->a, $this->frame, 2);   // 10 → 2 = −80%
        $this->sell($this->a, $this->sun, 45);     // fast mover, stock now 5

        $i = app(InsightsEngine::class)->all([$this->a->id]);
        $this->assertSame((int) $this->frame->id, (int) $i['declining_products'][0]['product_id']);
        $this->assertSame(-80, $i['declining_products'][0]['change_pct']);
        $this->assertSame([], $i['slow_moving'], 'Both products sold recently');
        $reorder = collect($i['reorder'])->firstWhere('product_id', $this->sun->id);
        $this->assertNotNull($reorder);
        $this->assertGreaterThan(0, $reorder['suggested']);
        $this->assertNotEmpty($i['forecast']);

        // Branch B had no sales: its stock is slow-moving there.
        $this->assertNotEmpty(app(InsightsEngine::class)->slowMoving([$this->b->id], now()));
    }

    public function test_reorder_suggestions_only_create_draft_purchase_orders(): void
    {
        $supplier = Supplier::factory()->create();
        $this->sun->forceFill(['supplier_id' => $supplier->id])->save();
        $this->sell($this->a, $this->sun, 45);
        $stockBefore = $this->qty($this->a, $this->sun);

        Livewire::test(BusinessInsights::class)
            ->set('branch', $this->a->id)
            ->set('selected', [$this->a->id.'-'.$this->sun->id])
            ->call('createDrafts');

        $po = PurchaseOrder::sole();
        $this->assertSame(PurchaseStatus::Draft, $po->status, 'Never auto-confirmed');
        $this->assertSame($supplier->id, $po->supplier_id);
        $this->assertSame($stockBefore, $this->qty($this->a, $this->sun), 'Stock unchanged');
    }

    public function test_ai_is_optional_and_off_by_default(): void
    {
        config(['erp.ai.enabled' => false]);
        $r = app(AiNarrator::class)->summarize(['x' => 1], [], 'test');
        $this->assertFalse($r['ok']);
        Livewire::test(BusinessInsights::class)->call('analyze')->assertSet('aiOk', false);
    }

    public function test_analytics_pages_render(): void
    {
        $this->sell($this->a, $this->frame, 1);
        $this->get(route('reports.executive', ['branch' => 0]))->assertOk();
        foreach (array_keys(ReportEngine::REPORTS) as $r) {
            $this->get(route('reports.center', ['report' => $r, 'branch' => 0]))->assertOk();
        }
        $this->get(route('reports.center', ['report' => 'sales_product', 'compare' => 1, 'branch' => 0]))->assertOk();
        $this->get(route('reports.insights', ['branch' => 0]))->assertOk();
    }
}
