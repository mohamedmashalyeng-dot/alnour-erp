<?php

namespace App\Modules\Reports\Services;

use App\Modules\Customers\Services\CustomerLedger;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Sales\Enums\SaleReturnStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Operational analytics (sales, profitability, inventory, purchasing, customers, suppliers).
 *
 * Every report returns the same shape so one screen, one comparison routine and one exporter
 * serve them all:
 *   ['columns' => [key => ['label' => .., 'type' => money|int|text|percent]], 'rows' => [[key => value]], 'totals' => [...]]
 *
 * Sales figures are NET of completed returns and exclude VAT; profit = net revenue − cost of
 * the units that were not restocked (cost snapshot taken at sale time).
 */
class ReportEngine
{
    public const REPORTS = [
        'sales_period' => 'Sales by period',
        'sales_branch' => 'Sales by branch',
        'sales_employee' => 'Sales by employee',
        'sales_product' => 'Sales by product',
        'sales_category' => 'Sales by category',
        'profitability' => 'Profitability by product',
        'inventory_movements' => 'Inventory movements',
        'low_stock' => 'Low-stock report',
        'purchasing' => 'Purchasing by supplier',
        'customers' => 'Customer report',
        'suppliers' => 'Supplier report',
    ];

    /** Reports that can be compared across two date ranges, and the metrics compared. */
    public const COMPARABLE = [
        'sales_period' => ['revenue', 'profit'], 'sales_branch' => ['revenue', 'profit'], 'sales_employee' => ['revenue', 'invoices'],
        'sales_product' => ['qty', 'revenue'], 'sales_category' => ['qty', 'revenue'], 'profitability' => ['revenue', 'profit'],
        'purchasing' => ['total'], 'customers' => ['revenue'],
    ];

    private const SALES_METRICS = [
        'invoices' => ['label' => 'Invoices', 'type' => 'int'],
        'qty' => ['label' => 'Units', 'type' => 'int'],
        'revenue' => ['label' => 'Net sales (excl. VAT)', 'type' => 'money'],
        'cost' => ['label' => 'Cost', 'type' => 'money'],
        'profit' => ['label' => 'Gross profit', 'type' => 'money'],
        'margin' => ['label' => 'Margin %', 'type' => 'percent'],
    ];

    /**
     * @param  list<int>  $branchIds
     * @param  array{group?:string}  $options
     */
    public function run(string $report, array $branchIds, string $from, string $to, array $options = []): array
    {
        $result = match ($report) {
            'sales_period' => $this->salesBy($this->periodExpr($options['group'] ?? 'day'), 'Period', $branchIds, $from, $to),
            'sales_branch' => $this->salesBy('b.code', 'Branch', $branchIds, $from, $to, ['branches as b', 'b.id', '=', 's.branch_id'], 'b.name'),
            'sales_employee' => $this->salesBy('u.id', 'Employee', $branchIds, $from, $to, ['users as u', 'u.id', '=', 's.cashier_id'], 'u.name'),
            'sales_product' => $this->salesBy('si.product_id', 'Product', $branchIds, $from, $to, null, 'si.product_name', 'si.sku'),
            'sales_category' => $this->salesBy('COALESCE(c.id, 0)', 'Category', $branchIds, $from, $to, ['products as p', 'p.id', '=', 'si.product_id'], 'c.name',
                null, ['categories as c', 'c.id', '=', 'p.category_id']),
            'profitability' => $this->profitability($branchIds, $from, $to),
            'inventory_movements' => $this->inventoryMovements($branchIds, $from, $to),
            'low_stock' => $this->lowStock($branchIds),
            'purchasing' => $this->purchasing($branchIds, $from, $to),
            'customers' => $this->customers($branchIds, $from, $to),
            'suppliers' => $this->suppliers($branchIds, $from, $to),
            default => throw new InvalidArgumentException("Unknown report [$report]."),
        };
        $result['title'] = self::REPORTS[$report];

        return $result;
    }

    /**
     * Compare a report over two date ranges: rows are matched by key; for each compared metric
     * the output has A, B, change and change %.
     */
    public function compare(string $report, array $branchIds, array $rangeA, array $rangeB, array $options = []): array
    {
        $metrics = self::COMPARABLE[$report] ?? throw new InvalidArgumentException('This report cannot be compared.');
        $a = $this->run($report, $branchIds, $rangeA[0], $rangeA[1], $options);
        $b = $this->run($report, $branchIds, $rangeB[0], $rangeB[1], $options);

        $columns = ['label' => $a['columns']['label']];
        foreach ($metrics as $m) {
            $type = $a['columns'][$m]['type'];
            $columns[$m.'_a'] = ['label' => __($a['columns'][$m]['label']).' (A)', 'type' => $type];
            $columns[$m.'_b'] = ['label' => __($a['columns'][$m]['label']).' (B)', 'type' => $type];
            $columns[$m.'_diff'] = ['label' => 'Change', 'type' => $type];
            $columns[$m.'_pct'] = ['label' => 'Change %', 'type' => 'percent'];
        }

        $rows = [];
        foreach ([[$a, 'a'], [$b, 'b']] as [$set, $side]) {
            foreach ($set['rows'] as $r) {
                $k = (string) $r['key'];
                $rows[$k] ??= ['key' => $k, 'label' => $r['label']];
                foreach ($metrics as $m) {
                    $rows[$k][$m.'_'.$side] = $r[$m] ?? 0;
                }
            }
        }
        foreach ($rows as &$r) {
            foreach ($metrics as $m) {
                $va = (int) ($r[$m.'_a'] ?? 0);
                $vb = (int) ($r[$m.'_b'] ?? 0);
                $r[$m.'_a'] = $va;
                $r[$m.'_b'] = $vb;
                $r[$m.'_diff'] = $vb - $va;
                $r[$m.'_pct'] = $va !== 0 ? intdiv(($vb - $va) * 10000, abs($va)) : null; // basis points
            }
        }
        unset($r);

        $totals = ['label' => 'Total'];
        foreach ($metrics as $m) {
            $ta = array_sum(array_column($rows, $m.'_a'));
            $tb = array_sum(array_column($rows, $m.'_b'));
            $totals += [$m.'_a' => $ta, $m.'_b' => $tb, $m.'_diff' => $tb - $ta, $m.'_pct' => $ta !== 0 ? intdiv(($tb - $ta) * 10000, abs($ta)) : null];
        }

        return ['title' => self::REPORTS[$report], 'columns' => $columns, 'rows' => array_values($rows), 'totals' => $totals];
    }

    // ---------------------------------------------------------------- sales

    /** Completed returns aggregated per sale line. */
    private function returnsPerLine(): Builder
    {
        return DB::table('sale_return_items as ri')
            ->join('sale_returns as sr', 'sr.id', '=', 'ri.sale_return_id')
            ->where('sr.status', SaleReturnStatus::Completed->value)
            ->groupBy('ri.sale_item_id')
            ->selectRaw('ri.sale_item_id, SUM(ri.quantity) as qty, SUM(ri.amount) as amount, SUM(ri.tax_amount) as tax,
                SUM(CASE WHEN ri.restocked = 1 THEN ri.quantity ELSE 0 END) as restocked_qty');
    }

    private function saleLines(array $branchIds, string $from, string $to): Builder
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->leftJoinSub($this->returnsPerLine(), 'r', 'r.sale_item_id', '=', 'si.id')
            ->whereIn('s.branch_id', $branchIds ?: [0])
            ->whereIn('s.status', CustomerLedger::OPEN_STATUSES)
            ->whereBetween('s.sold_at', [$from.' 00:00:00', $to.' 23:59:59']);
    }

    private function salesMetricsSql(): string
    {
        return 'COUNT(DISTINCT s.id) as invoices,
            SUM(si.quantity - COALESCE(r.qty, 0)) as qty,
            SUM(si.line_total - si.tax_amount - COALESCE(r.amount, 0) + COALESCE(r.tax, 0)) as revenue,
            SUM(si.unit_cost * (si.quantity - COALESCE(r.restocked_qty, 0))) as cost';
    }

    private function salesBy(string $keyExpr, string $dimension, array $branchIds, string $from, string $to,
        ?array $join = null, ?string $labelExpr = null, ?string $subLabelExpr = null, ?array $leftJoin = null): array
    {
        $q = $this->saleLines($branchIds, $from, $to);
        if ($join) {
            $q->join(...$join);
        }
        if ($leftJoin) {
            $q->leftJoin(...$leftJoin);
        }
        $label = $labelExpr ? "MAX($labelExpr)" : $keyExpr;
        $sub = $subLabelExpr ? ", MAX($subLabelExpr) as sub" : ', NULL as sub';
        $rows = $q->groupByRaw($keyExpr)->orderByRaw($dimension === 'Period' ? $keyExpr : 'revenue DESC')
            ->selectRaw("$keyExpr as k, $label as label $sub, ".$this->salesMetricsSql())
            ->get()->map(fn ($r) => $this->salesRow($r, $dimension))->all();

        return $this->withTotals(['label' => ['label' => $dimension, 'type' => 'text']] + self::SALES_METRICS, $rows);
    }

    private function salesRow(object $r, string $dimension): array
    {
        $revenue = (int) $r->revenue;
        $cost = (int) $r->cost;
        $label = (string) ($r->label ?? $r->k);
        if ($dimension === 'Category' && ! $r->label) {
            $label = __('Uncategorized');
        }

        return [
            'key' => (string) $r->k,
            'label' => $label.($r->sub ? ' ('.$r->sub.')' : ''),
            'invoices' => (int) $r->invoices,
            'qty' => (int) $r->qty,
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $revenue - $cost,
            'margin' => $revenue !== 0 ? intdiv(($revenue - $cost) * 10000, $revenue) : null,
        ];
    }

    private function profitability(array $branchIds, string $from, string $to): array
    {
        $r = $this->salesBy('si.product_id', 'Product', $branchIds, $from, $to, null, 'si.product_name', 'si.sku');
        usort($r['rows'], fn ($x, $y) => $y['profit'] <=> $x['profit']);

        return $r;
    }

    // ---------------------------------------------------------------- inventory / purchasing / parties

    private function inventoryMovements(array $branchIds, string $from, string $to): array
    {
        $types = ['opening', 'purchase_receipt', 'receipt', 'transfer_in', 'transfer_out', 'sale', 'sale_return', 'sale_cancel', 'adjustment_in', 'adjustment_out'];
        $select = ['t.product_id as k', 'MAX(p.name) as label', 'MAX(p.sku) as sku'];
        foreach ($types as $type) {
            $select[] = "SUM(CASE WHEN t.type = '$type' THEN t.quantity ELSE 0 END) as $type";
        }
        $rows = DB::table('inventory_transactions as t')->join('products as p', 'p.id', '=', 't.product_id')
            ->whereIn('t.branch_id', $branchIds ?: [0])
            ->whereBetween('t.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupBy('t.product_id')->selectRaw(implode(', ', $select).', SUM(t.quantity) as net')
            ->orderByDesc(DB::raw('SUM(ABS(t.quantity))'))->get()
            ->map(function ($r) use ($types) {
                $row = ['key' => (string) $r->k, 'label' => $r->label.' ('.$r->sku.')', 'net' => (int) $r->net];
                foreach ($types as $type) {
                    $row[$type] = (int) $r->{$type};
                }

                return $row;
            })->all();

        $columns = ['label' => ['label' => 'Product', 'type' => 'text']];
        foreach ($types as $type) {
            $columns[$type] = ['label' => 'movement.'.$type, 'type' => 'int'];
        }
        $columns['net'] = ['label' => 'Net change', 'type' => 'int'];

        return $this->withTotals($columns, $rows);
    }

    private function lowStock(array $branchIds): array
    {
        $rows = DB::table('inventory as i')->join('products as p', 'p.id', '=', 'i.product_id')->join('branches as b', 'b.id', '=', 'i.branch_id')
            ->whereIn('i.branch_id', $branchIds ?: [0])->where('i.min_stock_level', '>', 0)->whereColumn('i.quantity', '<=', 'i.min_stock_level')
            ->orderBy('i.quantity')
            ->get(['i.id', 'p.name', 'p.sku', 'b.code', 'i.quantity', 'i.reserved_quantity', 'i.min_stock_level'])
            ->map(fn ($r) => ['key' => (string) $r->id, 'label' => $r->name.' ('.$r->sku.')', 'branch' => $r->code,
                'qty' => (int) $r->quantity, 'reserved' => (int) $r->reserved_quantity, 'min' => (int) $r->min_stock_level,
                'shortage' => max(0, (int) $r->min_stock_level - (int) $r->quantity)])->all();

        return $this->withTotals([
            'label' => ['label' => 'Product', 'type' => 'text'], 'branch' => ['label' => 'Branch', 'type' => 'text'],
            'qty' => ['label' => 'On hand', 'type' => 'int'], 'reserved' => ['label' => 'Reserved', 'type' => 'int'],
            'min' => ['label' => 'Minimum', 'type' => 'int'], 'shortage' => ['label' => 'Shortage', 'type' => 'int'],
        ], $rows);
    }

    private function purchasing(array $branchIds, string $from, string $to): array
    {
        $rows = DB::table('purchase_orders as po')->join('suppliers as sp', 'sp.id', '=', 'po.supplier_id')
            ->whereIn('po.branch_id', $branchIds ?: [0])->where('po.status', PurchaseStatus::Received->value)
            ->whereBetween('po.received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupBy('sp.id')->selectRaw('sp.id as k, MAX(sp.name) as label, COUNT(*) as orders, SUM(po.total) as total, SUM(po.tax_total) as tax, SUM(po.paid_amount) as paid')
            ->orderByDesc('total')->get()
            ->map(fn ($r) => ['key' => (string) $r->k, 'label' => $r->label, 'orders' => (int) $r->orders, 'total' => (int) $r->total,
                'tax' => (int) $r->tax, 'paid' => (int) $r->paid, 'outstanding' => (int) $r->total - (int) $r->paid])->all();

        return $this->withTotals([
            'label' => ['label' => 'Supplier', 'type' => 'text'], 'orders' => ['label' => 'Orders', 'type' => 'int'],
            'total' => ['label' => 'Total purchases', 'type' => 'money'], 'tax' => ['label' => 'Tax', 'type' => 'money'],
            'paid' => ['label' => 'Paid', 'type' => 'money'], 'outstanding' => ['label' => 'Outstanding', 'type' => 'money'],
        ], $rows);
    }

    private function customers(array $branchIds, string $from, string $to): array
    {
        $rows = DB::table('sales as s')->join('customers as c', 'c.id', '=', 's.customer_id')
            ->whereIn('s.branch_id', $branchIds ?: [0])->whereIn('s.status', CustomerLedger::OPEN_STATUSES)
            ->whereBetween('s.sold_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupBy('c.id')
            ->selectRaw('c.id as k, MAX(c.name) as label, MAX(c.phone) as phone, COUNT(*) as invoices, SUM(s.total - s.returned_amount) as revenue,
                SUM(s.total - s.returned_amount - s.paid_amount + s.refunded_amount) as balance, MAX(s.sold_at) as last_purchase')
            ->orderByDesc('revenue')->limit(500)->get()
            ->map(fn ($r) => ['key' => (string) $r->k, 'label' => $r->label, 'phone' => $r->phone, 'invoices' => (int) $r->invoices,
                'revenue' => (int) $r->revenue, 'balance' => (int) $r->balance, 'last' => substr((string) $r->last_purchase, 0, 10)])->all();

        return $this->withTotals([
            'label' => ['label' => 'Customer', 'type' => 'text'], 'phone' => ['label' => 'Phone', 'type' => 'text'],
            'invoices' => ['label' => 'Invoices', 'type' => 'int'], 'revenue' => ['label' => 'Purchases (incl. VAT)', 'type' => 'money'],
            'balance' => ['label' => 'Outstanding balance', 'type' => 'money'], 'last' => ['label' => 'Last purchase', 'type' => 'text'],
        ], $rows);
    }

    private function suppliers(array $branchIds, string $from, string $to): array
    {
        $purch = DB::table('purchase_orders')->whereIn('branch_id', $branchIds ?: [0])->where('status', PurchaseStatus::Received->value)
            ->whereBetween('received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total) as total, COUNT(*) as orders');
        $paid = DB::table('payments')->whereIn('branch_id', $branchIds ?: [0])->where('party_type', 'supplier')->where('status', 'posted')
            ->whereBetween('paid_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(amount) as paid');
        $balance = DB::table('purchase_orders')->whereIn('branch_id', $branchIds ?: [0])->where('status', PurchaseStatus::Received->value)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total - paid_amount) as balance');

        $rows = DB::table('suppliers as sp')
            ->leftJoinSub($purch, 'pu', 'pu.supplier_id', '=', 'sp.id')
            ->leftJoinSub($paid, 'pa', 'pa.supplier_id', '=', 'sp.id')
            ->leftJoinSub($balance, 'ba', 'ba.supplier_id', '=', 'sp.id')
            ->where(fn ($w) => $w->whereNotNull('pu.total')->orWhereNotNull('pa.paid')->orWhere('ba.balance', '>', 0))
            ->orderByDesc('pu.total')
            ->get(['sp.id', 'sp.name', 'pu.orders', 'pu.total', 'pa.paid', 'ba.balance'])
            ->map(fn ($r) => ['key' => (string) $r->id, 'label' => $r->name, 'orders' => (int) $r->orders, 'total' => (int) $r->total,
                'paid' => (int) $r->paid, 'balance' => (int) $r->balance])->all();

        return $this->withTotals([
            'label' => ['label' => 'Supplier', 'type' => 'text'], 'orders' => ['label' => 'Orders received', 'type' => 'int'],
            'total' => ['label' => 'Purchases', 'type' => 'money'], 'paid' => ['label' => 'Payments', 'type' => 'money'],
            'balance' => ['label' => 'Outstanding balance', 'type' => 'money'],
        ], $rows);
    }

    // ---------------------------------------------------------------- helpers

    public function periodExpr(string $group): string
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        return match ($group) {
            'week' => $sqlite ? "strftime('%Y-W%W', s.sold_at)" : "DATE_FORMAT(s.sold_at, '%x-W%v')",
            'month' => $sqlite ? "strftime('%Y-%m', s.sold_at)" : "DATE_FORMAT(s.sold_at, '%Y-%m')",
            'year' => $sqlite ? "strftime('%Y', s.sold_at)" : "DATE_FORMAT(s.sold_at, '%Y')",
            default => $sqlite ? 'date(s.sold_at)' : 'DATE(s.sold_at)',
        };
    }

    private function withTotals(array $columns, array $rows): array
    {
        $totals = ['label' => 'Total'];
        foreach ($columns as $key => $col) {
            if (in_array($col['type'], ['money', 'int'], true)) {
                $totals[$key] = array_sum(array_map(fn ($r) => (int) ($r[$key] ?? 0), $rows));
            }
        }
        if (isset($columns['margin'], $totals['revenue'], $totals['cost'])) {
            $totals['margin'] = $totals['revenue'] !== 0 ? intdiv(($totals['revenue'] - $totals['cost']) * 10000, $totals['revenue']) : null;
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    /** @return Collection<int, array> top N rows of a report by a metric */
    public function top(string $report, array $branchIds, string $from, string $to, string $metric, int $n = 10): Collection
    {
        return collect($this->run($report, $branchIds, $from, $to)['rows'])->sortByDesc($metric)->take($n)->values();
    }
}
