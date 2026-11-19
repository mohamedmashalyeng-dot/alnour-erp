<?php

namespace App\Modules\Suppliers\Services;

use App\Modules\Payments\Models\Payment;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Suppliers\Models\Supplier;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Supplier balances are derived (never stored):
 *   payable = SUM(received purchase totals) - SUM(posted supplier payments)
 */
class SupplierLedger
{
    public function withTotals(Builder $query): Builder
    {
        return $query->addSelect([
            'total_purchases' => PurchaseOrder::query()->selectRaw('COALESCE(SUM(total),0)')
                ->whereColumn('supplier_id', 'suppliers.id')->where('status', PurchaseStatus::Received->value),
            'total_paid' => Payment::query()->selectRaw('COALESCE(SUM(amount),0)')
                ->whereColumn('supplier_id', 'suppliers.id')->where('party_type', 'supplier')
                ->where('direction', Payment::OUT)->where('status', 'posted'),
        ]);
    }

    public function onlyWithBalance(Builder $query): Builder
    {
        return $query->whereRaw('(
            (SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE purchase_orders.supplier_id = suppliers.id AND status = ?)
          - (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payments.supplier_id = suppliers.id AND party_type = ? AND direction = ? AND status = ?)
        ) > 0', [PurchaseStatus::Received->value, 'supplier', Payment::OUT, 'posted']);
    }

    /** @return array{purchases:int, payments:int, balance:int} */
    public function totals(Supplier $supplier): array
    {
        $purchases = (int) PurchaseOrder::where('supplier_id', $supplier->id)->where('status', PurchaseStatus::Received->value)->sum('total');
        $payments = (int) Payment::where('supplier_id', $supplier->id)->where('party_type', 'supplier')
            ->where('direction', Payment::OUT)->where('status', 'posted')->sum('amount');

        return ['purchases' => $purchases, 'payments' => $payments, 'balance' => $purchases - $payments];
    }

    /**
     * Chronological statement with running balance (credit = we owe more, debit = we paid).
     *
     * @return Collection<int, array{date:CarbonInterface, type:string, reference:string, description:string, debit:int, credit:int, balance:int}>
     */
    public function statement(Supplier $supplier, ?string $from = null, ?string $to = null): array
    {
        $entries = collect();

        PurchaseOrder::where('supplier_id', $supplier->id)->where('status', PurchaseStatus::Received->value)
            ->get(['id', 'number', 'supplier_invoice_number', 'received_at', 'total'])
            ->each(fn ($po) => $entries->push([
                'date' => $po->received_at, 'type' => 'purchase', 'reference' => $po->number,
                'description' => $po->supplier_invoice_number ? __('Supplier invoice :n', ['n' => $po->supplier_invoice_number]) : __('Purchase received'),
                'debit' => 0, 'credit' => $po->total, 'id' => $po->id,
            ]));

        Payment::where('supplier_id', $supplier->id)->where('party_type', 'supplier')->where('status', 'posted')
            ->get(['id', 'number', 'paid_at', 'amount', 'method', 'reference', 'direction'])
            ->each(fn ($p) => $entries->push([
                'date' => $p->paid_at, 'type' => 'payment', 'reference' => $p->number,
                'description' => $p->method->label().($p->reference ? ' — '.$p->reference : ''),
                'debit' => $p->direction === Payment::OUT ? $p->amount : 0,
                'credit' => $p->direction === Payment::IN ? $p->amount : 0, 'id' => $p->id,
            ]));

        $entries = $entries->sortBy(fn ($e) => $e['date']->format('YmdHis').str_pad((string) $e['id'], 10, '0', STR_PAD_LEFT))->values();

        $opening = 0;
        $balance = 0;
        $rows = [];
        foreach ($entries as $e) {
            $balance += $e['credit'] - $e['debit'];
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
