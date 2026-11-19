<?php

namespace App\Modules\Sales\Services;

use App\Modules\Customers\Services\CustomerLedger;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Operational sales figures (not accounting statements — those come from the Phase 3 ledger).
 */
class SalesReport
{
    /**
     * @param  list<int>  $branchIds
     */
    public function summary(array $branchIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $sales = Sale::query()->whereIn('branch_id', $branchIds)
            ->whereIn('status', CustomerLedger::OPEN_STATUSES)
            ->whereBetween('sold_at', [$from, $to]);

        $row = (clone $sales)->selectRaw('COUNT(*) as invoices, COALESCE(SUM(total),0) as gross,
            COALESCE(SUM(returned_amount),0) as returned, COALESCE(SUM(discount_total),0) as discounts,
            COALESCE(SUM(tax_total),0) as tax')->first();

        $payments = Payment::query()->whereIn('branch_id', $branchIds)->where('party_type', 'customer')
            ->where('status', 'posted')->where('method', '!=', 'store_credit')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END),0) as received,
                COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END),0) as refunded")->first();

        $receivables = (int) Sale::query()->whereIn('branch_id', $branchIds)
            ->whereIn('status', CustomerLedger::OPEN_STATUSES)
            ->sum(DB::raw('total - returned_amount - paid_amount + refunded_amount'));

        return [
            'invoices' => (int) $row->invoices,
            'gross_sales' => (int) $row->gross,
            'returns' => (int) $row->returned,
            'net_sales' => (int) $row->gross - (int) $row->returned,
            'discounts' => (int) $row->discounts,
            'tax' => (int) $row->tax,
            'collected' => (int) $payments->received - (int) $payments->refunded,
            'received' => (int) $payments->received,
            'refunded' => (int) $payments->refunded,
            'receivables' => max(0, $receivables),
            'average' => (int) $row->invoices > 0 ? intdiv((int) $row->gross, (int) $row->invoices) : 0,
        ];
    }

    public function byPaymentMethod(array $branchIds, CarbonInterface $from, CarbonInterface $to): array
    {
        return Payment::query()->whereIn('branch_id', $branchIds)->where('party_type', 'customer')->where('status', 'posted')
            ->whereBetween('paid_at', [$from, $to])
            ->groupBy('method')
            ->selectRaw("method, COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END),0) as net")
            ->pluck('net', 'method')->map(fn ($v) => (int) $v)->all();
    }

    public function byBranch(array $branchIds, CarbonInterface $from, CarbonInterface $to)
    {
        return Sale::query()->join('branches', 'branches.id', '=', 'sales.branch_id')
            ->whereIn('sales.branch_id', $branchIds)->whereIn('sales.status', CustomerLedger::OPEN_STATUSES)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->groupBy('branches.id', 'branches.name', 'branches.name_en', 'branches.code')
            ->selectRaw('branches.id, branches.name, branches.name_en, branches.code, COUNT(*) as invoices, COALESCE(SUM(sales.total - sales.returned_amount),0) as net')
            ->orderByDesc('net')->get();
    }

    public function byCashier(array $branchIds, CarbonInterface $from, CarbonInterface $to)
    {
        return Sale::query()->join('users', 'users.id', '=', 'sales.cashier_id')
            ->whereIn('sales.branch_id', $branchIds)->whereIn('sales.status', CustomerLedger::OPEN_STATUSES)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.id, users.name, COUNT(*) as invoices, COALESCE(SUM(sales.total - sales.returned_amount),0) as net')
            ->orderByDesc('net')->get();
    }

    public function topProducts(array $branchIds, CarbonInterface $from, CarbonInterface $to, int $limit = 10)
    {
        return DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.branch_id', $branchIds)->whereIn('sales.status', CustomerLedger::OPEN_STATUSES)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'sale_items.sku')
            ->selectRaw('sale_items.product_id, sale_items.product_name, sale_items.sku,
                SUM(sale_items.quantity - sale_items.returned_quantity) as qty, SUM(sale_items.line_total) as amount')
            ->orderByDesc('qty')->limit($limit)->get();
    }
}
