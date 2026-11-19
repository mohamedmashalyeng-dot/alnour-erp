<?php

namespace App\Modules\Reports\Services;

use App\Modules\Core\Services\Settings;
use App\Modules\Customers\Services\CustomerLedger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic business analytics. Works without any AI provider; the optional AI narrator
 * only summarizes what this engine computes. Nothing here writes to the database: every
 * recommendation requires a human action (e.g. creating a DRAFT purchase order).
 *
 * All queries are restricted to the branch ids passed in (the caller's authorized branches).
 */
class InsightsEngine
{
    public function __construct(private Settings $settings) {}

    public function all(array $branchIds, ?string $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? today())->endOfDay();

        return [
            'as_of' => $asOf->toDateString(),
            'declining_products' => $this->decliningProducts($branchIds, $asOf),
            'declining_branches' => $this->decliningBranches($branchIds, $asOf),
            'unusual_movements' => $this->unusualMovements($branchIds, $asOf),
            'slow_moving' => $this->slowMoving($branchIds, $asOf),
            'reorder' => $this->reorderSuggestions($branchIds, $asOf),
            'forecast' => $this->forecast($branchIds, $asOf),
            'profitable' => $this->profitableProducts($branchIds, $asOf),
        ];
    }

    /** Net units sold per product (and optionally branch) in a window. */
    private function unitsSold(array $branchIds, Carbon $from, Carbon $to, bool $perBranch = false): Collection
    {
        $q = DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')
            ->whereIn('s.branch_id', $branchIds ?: [0])->whereIn('s.status', CustomerLedger::OPEN_STATUSES)
            ->whereBetween('s.sold_at', [$from, $to]);
        $keys = $perBranch ? ['si.product_id', 's.branch_id'] : ['si.product_id'];

        return $q->groupBy(...$keys)
            ->selectRaw(implode(', ', $keys).', MAX(si.product_name) as name, MAX(si.sku) as sku, SUM(si.quantity - si.returned_quantity) as qty,
                SUM(si.line_total - si.tax_amount) as revenue, SUM(si.unit_cost * (si.quantity - si.returned_quantity)) as cost')
            ->get();
    }

    /** Products whose units sold in the last 30 days fell ≥ 30% vs the previous 30 days. */
    public function decliningProducts(array $branchIds, Carbon $asOf): array
    {
        $cur = $this->unitsSold($branchIds, $asOf->copy()->subDays(30)->startOfDay(), $asOf)->keyBy('product_id');
        $prev = $this->unitsSold($branchIds, $asOf->copy()->subDays(60)->startOfDay(), $asOf->copy()->subDays(31)->endOfDay());

        return $prev->filter(fn ($p) => (int) $p->qty >= 3)->map(function ($p) use ($cur) {
            $now = (int) ($cur[$p->product_id]->qty ?? 0);
            $change = intdiv(($now - (int) $p->qty) * 100, (int) $p->qty);

            return ['product_id' => $p->product_id, 'name' => $p->name, 'sku' => $p->sku, 'previous' => (int) $p->qty, 'current' => $now, 'change_pct' => $change];
        })->filter(fn ($r) => $r['change_pct'] <= -30)->sortBy('change_pct')->values()->take(20)->all();
    }

    public function decliningBranches(array $branchIds, Carbon $asOf): array
    {
        $sum = fn (Carbon $f, Carbon $t) => DB::table('sales')->whereIn('branch_id', $branchIds ?: [0])->whereIn('status', CustomerLedger::OPEN_STATUSES)
            ->whereBetween('sold_at', [$f, $t])->groupBy('branch_id')->selectRaw('branch_id, SUM(total - returned_amount) as net')->pluck('net', 'branch_id');
        $cur = $sum($asOf->copy()->subDays(30)->startOfDay(), $asOf);
        $prev = $sum($asOf->copy()->subDays(60)->startOfDay(), $asOf->copy()->subDays(31)->endOfDay());
        $codes = DB::table('branches')->whereIn('id', $branchIds ?: [0])->pluck('code', 'id');

        $out = [];
        foreach ($prev as $branch => $p) {
            $p = (int) $p;
            $c = (int) ($cur[$branch] ?? 0);
            if ($p > 0 && ($change = intdiv(($c - $p) * 100, $p)) <= -15) {
                $out[] = ['branch' => $codes[$branch] ?? $branch, 'previous' => $p, 'current' => $c, 'change_pct' => $change];
            }
        }

        return $out;
    }

    /**
     * Manual adjustments in the last 30 days that are unusually large (≥ 3× the median adjustment
     * size and at least 5 units), plus any negative stock.
     */
    public function unusualMovements(array $branchIds, Carbon $asOf): array
    {
        $adj = DB::table('inventory_transactions as t')->join('products as p', 'p.id', '=', 't.product_id')->join('branches as b', 'b.id', '=', 't.branch_id')
            ->whereIn('t.branch_id', $branchIds ?: [0])->whereIn('t.type', ['adjustment_in', 'adjustment_out'])
            ->whereBetween('t.created_at', [$asOf->copy()->subDays(30)->startOfDay(), $asOf])
            ->get(['t.id', 't.quantity', 't.unit_cost', 't.reason', 't.created_at', 'p.name', 'p.sku', 'b.code']);
        $sizes = $adj->map(fn ($a) => abs((int) $a->quantity))->sort()->values();
        $median = $sizes->isEmpty() ? 0 : (int) $sizes[intdiv($sizes->count(), 2)];
        $threshold = max(5, $median * 3);

        $out = $adj->filter(fn ($a) => abs((int) $a->quantity) >= $threshold)->map(fn ($a) => [
            'kind' => 'large_adjustment', 'name' => $a->name, 'sku' => $a->sku, 'branch' => $a->code, 'quantity' => (int) $a->quantity,
            'value' => abs((int) $a->quantity) * (int) $a->unit_cost, 'reason' => $a->reason, 'date' => substr((string) $a->created_at, 0, 10),
        ])->values()->all();

        foreach (DB::table('inventory as i')->join('products as p', 'p.id', '=', 'i.product_id')->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->whereIn('i.branch_id', $branchIds ?: [0])->where('i.quantity', '<', 0)->get(['p.name', 'p.sku', 'b.code', 'i.quantity']) as $n) {
            $out[] = ['kind' => 'negative_stock', 'name' => $n->name, 'sku' => $n->sku, 'branch' => $n->code, 'quantity' => (int) $n->quantity, 'value' => 0, 'reason' => null, 'date' => null];
        }

        return $out;
    }

    /** Stock on hand with no sale in the last 90 days, by tied-up value. */
    public function slowMoving(array $branchIds, Carbon $asOf, int $days = 90): array
    {
        $sold = DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')
            ->whereIn('s.branch_id', $branchIds ?: [0])->whereIn('s.status', CustomerLedger::OPEN_STATUSES)
            ->where('s.sold_at', '>=', $asOf->copy()->subDays($days))->distinct()->pluck('si.product_id');

        return DB::table('inventory as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereIn('i.branch_id', $branchIds ?: [0])->where('i.quantity', '>', 0)->where('p.is_active', true)
            ->whereNotIn('i.product_id', $sold->all() ?: [0])
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id, MAX(p.name) as name, MAX(p.sku) as sku, SUM(i.quantity) as qty, SUM(i.quantity) * MAX(p.cost_price) as value')
            ->orderByDesc('value')->limit(30)->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'name' => $r->name, 'sku' => $r->sku, 'qty' => (int) $r->qty, 'value' => (int) $r->value])->all();
    }

    /**
     * Reorder suggestion per branch/product: average daily sales over 60 days × (lead time + safety days)
     * minus available stock. Only suggested when stock will not cover the lead time or is below minimum.
     */
    public function reorderSuggestions(array $branchIds, Carbon $asOf): array
    {
        $lead = (int) $this->settings->get('reorder_lead_days', 14);
        $safety = (int) $this->settings->get('reorder_safety_days', 7);
        $window = 60;
        $sales = $this->unitsSold($branchIds, $asOf->copy()->subDays($window)->startOfDay(), $asOf, perBranch: true);
        $stock = DB::table('inventory')->whereIn('branch_id', $branchIds ?: [0])->get()->keyBy(fn ($r) => $r->branch_id.'-'.$r->product_id);
        $products = DB::table('products')->whereIn('id', $sales->pluck('product_id')->merge($stock->pluck('product_id'))->unique()->all() ?: [0])
            ->get(['id', 'supplier_id', 'purchase_price', 'is_active', 'track_stock'])->keyBy('id');
        $codes = DB::table('branches')->whereIn('id', $branchIds ?: [0])->pluck('code', 'id');

        $out = [];
        $seen = [];
        foreach ($sales as $s) {
            $key = $s->branch_id.'-'.$s->product_id;
            $seen[$key] = true;
            $p = $products[$s->product_id] ?? null;
            if (! $p || ! $p->is_active || ! $p->track_stock) {
                continue;
            }
            $row = $stock[$key] ?? null;
            $available = $row ? (int) $row->quantity - (int) $row->reserved_quantity : 0;
            $daily = (int) $s->qty / $window; // quantities, not money: float is acceptable here
            $need = (int) ceil($daily * ($lead + $safety)) - $available;
            $belowMin = $row && (int) $row->min_stock_level > 0 && (int) $row->quantity <= (int) $row->min_stock_level;
            if ($need > 0 && ($available < $daily * $lead || $belowMin)) {
                $out[] = ['branch_id' => (int) $s->branch_id, 'branch' => $codes[$s->branch_id] ?? '', 'product_id' => (int) $s->product_id,
                    'name' => $s->name, 'sku' => $s->sku, 'available' => $available, 'daily' => round($daily, 2),
                    'days_left' => $daily > 0 ? (int) floor(max(0, $available) / $daily) : null, 'suggested' => $need,
                    'supplier_id' => $p->supplier_id, 'unit_cost' => (int) $p->purchase_price];
            }
        }
        // Below-minimum items with no recent sales still get topped up to their minimum.
        foreach ($stock as $key => $row) {
            if (isset($seen[$key]) || (int) $row->min_stock_level <= 0 || (int) $row->quantity > (int) $row->min_stock_level) {
                continue;
            }
            $meta = DB::table('products')->where('id', $row->product_id)->first(['name', 'sku', 'supplier_id', 'purchase_price', 'is_active']);
            if (! $meta || ! $meta->is_active) {
                continue;
            }
            $out[] = ['branch_id' => (int) $row->branch_id, 'branch' => $codes[$row->branch_id] ?? '', 'product_id' => (int) $row->product_id,
                'name' => $meta->name, 'sku' => $meta->sku, 'available' => (int) $row->quantity - (int) $row->reserved_quantity, 'daily' => 0.0,
                'days_left' => null, 'suggested' => max(1, (int) $row->min_stock_level * 2 - (int) $row->quantity),
                'supplier_id' => $meta->supplier_id, 'unit_cost' => (int) $meta->purchase_price];
        }
        usort($out, fn ($a, $b) => ($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX));

        return $out;
    }

    /**
     * Demand forecast for the 10 best sellers: least-squares trend over the last 12 weekly totals,
     * projected for the next 4 weeks (never negative).
     */
    public function forecast(array $branchIds, Carbon $asOf, int $weeks = 12): array
    {
        $start = $asOf->copy()->subWeeks($weeks)->startOfDay();
        $top = $this->unitsSold($branchIds, $start, $asOf)->sortByDesc('qty')->take(10);
        $out = [];
        foreach ($top as $p) {
            $series = array_fill(0, $weeks, 0);
            $rows = DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')
                ->whereIn('s.branch_id', $branchIds ?: [0])->whereIn('s.status', CustomerLedger::OPEN_STATUSES)
                ->where('si.product_id', $p->product_id)->whereBetween('s.sold_at', [$start, $asOf])
                ->get(['s.sold_at', 'si.quantity', 'si.returned_quantity']);
            foreach ($rows as $r) {
                $w = min($weeks - 1, intdiv((int) $start->diffInDays(Carbon::parse($r->sold_at)), 7));
                $series[$w] += (int) $r->quantity - (int) $r->returned_quantity;
            }
            [$slope, $intercept] = $this->trend($series);
            $next = [];
            for ($i = 0; $i < 4; $i++) {
                $next[] = max(0, (int) round($intercept + $slope * ($weeks + $i)));
            }
            $out[] = ['product_id' => $p->product_id, 'name' => $p->name, 'sku' => $p->sku, 'history' => $series,
                'trend' => $slope > 0.1 ? 'up' : ($slope < -0.1 ? 'down' : 'flat'), 'next_4_weeks' => $next, 'next_total' => array_sum($next)];
        }

        return $out;
    }

    /** @return array{0: float, 1: float} slope, intercept */
    private function trend(array $y): array
    {
        $n = count($y);
        $sx = $sy = $sxy = $sxx = 0;
        foreach ($y as $x => $v) {
            $sx += $x;
            $sy += $v;
            $sxy += $x * $v;
            $sxx += $x * $x;
        }
        $den = $n * $sxx - $sx * $sx;
        $slope = $den ? ($n * $sxy - $sx * $sy) / $den : 0.0;

        return [$slope, ($sy - $slope * $sx) / max(1, $n)];
    }

    /** Most profitable products over 90 days with their margin and 30-day growth. */
    public function profitableProducts(array $branchIds, Carbon $asOf): array
    {
        $q90 = $this->unitsSold($branchIds, $asOf->copy()->subDays(90)->startOfDay(), $asOf);
        $cur = $this->unitsSold($branchIds, $asOf->copy()->subDays(30)->startOfDay(), $asOf)->keyBy('product_id');
        $prev = $this->unitsSold($branchIds, $asOf->copy()->subDays(60)->startOfDay(), $asOf->copy()->subDays(31)->endOfDay())->keyBy('product_id');

        return $q90->map(function ($p) use ($cur, $prev) {
            $profit = (int) $p->revenue - (int) $p->cost;
            $c = (int) ($cur[$p->product_id]->revenue ?? 0);
            $pv = (int) ($prev[$p->product_id]->revenue ?? 0);

            return ['product_id' => $p->product_id, 'name' => $p->name, 'sku' => $p->sku, 'revenue' => (int) $p->revenue, 'profit' => $profit,
                'margin_pct' => (int) $p->revenue > 0 ? intdiv($profit * 100, (int) $p->revenue) : 0,
                'growth_pct' => $pv > 0 ? intdiv(($c - $pv) * 100, $pv) : null];
        })->sortByDesc('profit')->take(15)->values()->all();
    }
}
