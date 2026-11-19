<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Enums\SaleReturnStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer receivables are derived from documents (never stored separately):
 *   balance = SUM over non-draft, non-cancelled sales of (total - returned - paid + refunded)
 */
class CustomerLedger
{
    public const OPEN_STATUSES = ['completed', 'partially_paid', 'refunded'];

    public function withTotals(Builder $query): Builder
    {
        return $query->addSelect([
            'total_purchases' => Sale::query()->selectRaw('COALESCE(SUM(total - returned_amount),0)')
                ->whereColumn('customer_id', 'customers.id')->whereIn('status', self::OPEN_STATUSES),
            'balance' => Sale::query()->selectRaw('COALESCE(SUM(total - returned_amount - paid_amount + refunded_amount),0)')
                ->whereColumn('customer_id', 'customers.id')->whereIn('status', self::OPEN_STATUSES),
        ]);
    }

    public function onlyWithBalance(Builder $query): Builder
    {
        return $query->whereRaw('(SELECT COALESCE(SUM(total - returned_amount - paid_amount + refunded_amount),0) FROM sales WHERE sales.customer_id = customers.id AND status IN (?, ?, ?)) > 0', self::OPEN_STATUSES);
    }

    /** @return array{purchases:int, paid:int, balance:int, invoices:int} */
    public function totals(Customer $customer): array
    {
        $row = Sale::where('customer_id', $customer->id)->whereIn('status', self::OPEN_STATUSES)
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(total - returned_amount),0) as purchases,
                COALESCE(SUM(paid_amount - refunded_amount),0) as paid,
                COALESCE(SUM(total - returned_amount - paid_amount + refunded_amount),0) as balance')
            ->first();

        return ['purchases' => (int) $row->purchases, 'paid' => (int) $row->paid, 'balance' => (int) $row->balance, 'invoices' => (int) $row->invoices];
    }

    /**
     * Chronological statement: invoices (debit), returns (credit), payments (credit), refunds (debit).
     */
    public function statement(Customer $customer, ?string $from = null, ?string $to = null): array
    {
        $entries = collect();

        Sale::where('customer_id', $customer->id)->whereIn('status', self::OPEN_STATUSES)
            ->get(['id', 'invoice_number', 'sold_at', 'total'])
            ->each(fn ($s) => $entries->push(['date' => $s->sold_at, 'type' => 'invoice', 'reference' => $s->invoice_number,
                'description' => __('Sales invoice'), 'debit' => $s->total, 'credit' => 0, 'id' => $s->id]));

        SaleReturn::where('customer_id', $customer->id)->where('status', SaleReturnStatus::Completed->value)
            ->whereHas('sale', fn ($q) => $q->whereIn('status', self::OPEN_STATUSES))
            ->get(['id', 'number', 'inspected_at', 'total'])
            ->each(fn ($r) => $entries->push(['date' => $r->inspected_at, 'type' => 'return', 'reference' => $r->number,
                'description' => __('Customer return'), 'debit' => 0, 'credit' => $r->total, 'id' => $r->id]));

        // Only payments allocated to open (non-cancelled) invoices affect the balance.
        Payment::query()
            ->where('customer_id', $customer->id)->where('party_type', 'customer')->where('status', 'posted')
            ->whereHas('allocations', fn ($q) => $q->where('allocatable_type', 'sale')
                ->whereIn('allocatable_id', Sale::select('id')->where('customer_id', $customer->id)->whereIn('status', self::OPEN_STATUSES)))
            ->get(['id', 'number', 'paid_at', 'amount', 'method', 'direction', 'reference'])
            ->each(fn ($p) => $entries->push(['date' => $p->paid_at, 'type' => $p->direction === Payment::IN ? 'payment' : 'refund',
                'reference' => $p->number, 'description' => ($p->direction === Payment::IN ? __('Payment') : __('Refund')).' — '.$p->method->label(),
                'debit' => $p->direction === Payment::OUT ? $p->amount : 0, 'credit' => $p->direction === Payment::IN ? $p->amount : 0, 'id' => $p->id]));

        $entries = $entries->sortBy(fn ($e) => $e['date']->format('YmdHis').$e['type'].str_pad((string) $e['id'], 10, '0', STR_PAD_LEFT))->values();

        $balance = 0;
        $opening = 0;
        $rows = [];
        foreach ($entries as $e) {
            $balance += $e['debit'] - $e['credit'];
            if ($from && $e['date']->lt($from.' 00:00:00')) {
                $opening = $balance;

                continue;
            }
            if ($to && $e['date']->gt($to.' 23:59:59')) {
                continue;
            }
            $rows[] = $e + ['balance' => $balance];
        }

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $rows ? end($rows)['balance'] : $opening];
    }
}
