<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Treasury\Services\TreasuryService;

/**
 * Translates business documents into balanced journal entries (perpetual inventory).
 * Every method is idempotent (LedgerService keys entries by source + event), so it can be
 * called from live events and from the history backfill command alike.
 */
class AccountingPoster
{
    public function __construct(
        private LedgerService $ledger,
        private TreasuryService $treasury,
    ) {}

    /**
     * Sale: Dr AR (total) / Cr Sales (net of VAT) / Cr VAT output;  Dr COGS / Cr Inventory (at cost snapshot).
     */
    public function sale(Sale $sale): ?JournalEntry
    {
        $cost = $this->stockValue(MovementType::Sale, 'sale_item', $sale->items()->pluck('id')->all());
        $lines = [
            ['account' => 'ar', 'debit' => $sale->total, 'party_type' => $sale->customer_id ? 'customer' : null, 'party_id' => $sale->customer_id],
            ['account' => 'sales.revenue', 'credit' => $sale->total - $sale->tax_total],
            ['account' => 'vat.output', 'credit' => $sale->tax_total],
            ['account' => 'cogs', 'debit' => $cost],
            ['account' => 'inventory', 'credit' => $cost],
        ];

        return $this->postIfAny([
            'entry_date' => $sale->sold_at ?? now(), 'branch_id' => $sale->branch_id, 'source' => $sale, 'event' => 'sale',
            'memo' => __('Sales invoice :number', ['number' => $sale->invoice_number]),
        ], $lines);
    }

    public function saleCancelled(Sale $sale): ?JournalEntry
    {
        return $this->ledger->reverseSource($sale, 'sale', __('Sale cancelled'));
    }

    /**
     * Return: Dr Sales returns + Dr VAT output / Cr AR;  restocked lines: Dr Inventory / Cr COGS.
     * Items not restocked (written off) keep their cost in COGS.
     */
    public function saleReturn(SaleReturn $return): ?JournalEntry
    {
        $cost = $this->stockValue(MovementType::SaleReturn, 'sale_return_item', $return->items()->pluck('id')->all());
        $lines = [
            ['account' => 'sales.returns', 'debit' => $return->total - $return->tax_total],
            ['account' => 'vat.output', 'debit' => $return->tax_total],
            ['account' => 'ar', 'credit' => $return->total, 'party_type' => $return->customer_id ? 'customer' : null, 'party_id' => $return->customer_id],
            ['account' => 'inventory', 'debit' => $cost],
            ['account' => 'cogs', 'credit' => $cost],
        ];

        return $this->postIfAny([
            'entry_date' => $return->inspected_at ?? now(), 'branch_id' => $return->branch_id, 'source' => $return, 'event' => 'return',
            'memo' => __('Customer return :number', ['number' => $return->number]),
        ], $lines);
    }

    /**
     * Payments reuse the Phase 2 payment rows (never duplicated):
     *   customer in : Dr cash/bank/card-clearing   Cr AR
     *   customer out: Dr AR                        Cr cash/bank
     *   supplier out: Dr AP                        Cr cash/bank
     *   store credit moves through "customer credit" instead of cash.
     */
    public function payment(Payment $payment): ?JournalEntry
    {
        $money = $this->treasury->paymentAccountId($payment);
        $party = $payment->party_type === 'supplier'
            ? ['account' => 'ap', 'party_type' => 'supplier', 'party_id' => $payment->supplier_id]
            : ['account' => 'ar', 'party_type' => $payment->customer_id ? 'customer' : null, 'party_id' => $payment->customer_id];

        $in = $payment->direction === Payment::IN;
        $lines = [
            ['account' => $money, 'debit' => $in ? $payment->amount : 0, 'credit' => $in ? 0 : $payment->amount],
            $party + ['debit' => $in ? 0 : $payment->amount, 'credit' => $in ? $payment->amount : 0],
        ];
        $label = $payment->method === PaymentMethod::StoreCredit ? __('Store credit') : $payment->method->label();

        return $this->postIfAny([
            'entry_date' => $payment->paid_at ?? now(), 'branch_id' => $payment->branch_id, 'source' => $payment, 'event' => 'payment',
            'memo' => __('Payment :number', ['number' => $payment->number]).' — '.$label,
        ], $lines);
    }

    /**
     * Purchase received: Dr Inventory (net of VAT) + Dr VAT input / Cr AP (supplier).
     */
    public function purchaseReceived(PurchaseOrder $po): ?JournalEntry
    {
        return $this->postIfAny([
            'entry_date' => $po->received_at ?? now(), 'branch_id' => $po->branch_id, 'source' => $po, 'event' => 'receipt',
            'memo' => __('Purchase :number', ['number' => $po->number]).($po->supplier_invoice_number ? ' — '.$po->supplier_invoice_number : ''),
        ], [
            ['account' => 'inventory', 'debit' => $po->total - $po->tax_total],
            ['account' => 'vat.input', 'debit' => $po->tax_total],
            ['account' => 'ap', 'credit' => $po->total, 'party_type' => 'supplier', 'party_id' => $po->supplier_id],
        ]);
    }

    /**
     * Manual stock movements valued at their unit cost:
     *   opening  : Dr Inventory / Cr Opening balance equity
     *   receipt  : Dr Inventory / Cr Goods received not invoiced
     *   adj. out : Dr Shrinkage / Cr Inventory      adj. in: Dr Inventory / Cr Count gains
     */
    public function inventoryAdjusted(InventoryTransaction $tx): ?JournalEntry
    {
        $value = abs($tx->quantity) * (int) $tx->unit_cost;
        $counter = match ($tx->type) {
            MovementType::Opening => 'opening.equity',
            MovementType::Receipt => 'grni',
            MovementType::AdjustmentOut => 'inventory.shrinkage',
            MovementType::AdjustmentIn => 'inventory.gain',
            default => null,
        };
        if (! $counter) {
            return null;
        }
        $increase = $tx->quantity > 0;

        return $this->postIfAny([
            'entry_date' => $tx->created_at ?? now(), 'branch_id' => $tx->branch_id, 'source' => $tx, 'event' => 'stock',
            'memo' => $tx->type->label().($tx->reason ? ' — '.$tx->reason : ''),
        ], [
            ['account' => 'inventory', 'debit' => $increase ? $value : 0, 'credit' => $increase ? 0 : $value],
            ['account' => $counter, 'debit' => $increase ? 0 : $value, 'credit' => $increase ? $value : 0],
        ]);
    }

    /**
     * Transfer received: inventory moves between branch dimensions through the inter-branch clearing
     * account, so each branch's books stay balanced and the company total is unchanged.
     */
    public function transferReceived(StockTransfer $transfer): ?JournalEntry
    {
        $value = $this->stockValue(MovementType::TransferOut, 'stock_transfer_item', $transfer->items()->pluck('id')->all());

        return $this->postIfAny([
            'entry_date' => $transfer->received_at ?? now(), 'branch_id' => $transfer->to_branch_id, 'source' => $transfer, 'event' => 'transfer',
            'memo' => __('Transfer :number', ['number' => $transfer->number]),
        ], [
            ['account' => 'inventory', 'debit' => $value, 'branch_id' => $transfer->to_branch_id],
            ['account' => 'interbranch', 'credit' => $value, 'branch_id' => $transfer->to_branch_id],
            ['account' => 'interbranch', 'debit' => $value, 'branch_id' => $transfer->from_branch_id],
            ['account' => 'inventory', 'credit' => $value, 'branch_id' => $transfer->from_branch_id],
        ]);
    }

    // ----------------------------------------------------------------

    /** Σ |qty| × unit_cost of the stock ledger rows for the given document lines. */
    private function stockValue(MovementType $type, string $referenceType, array $referenceIds): int
    {
        if ($referenceIds === []) {
            return 0;
        }

        return (int) InventoryTransaction::query()
            ->where('type', $type->value)->where('reference_type', $referenceType)->whereIn('reference_id', $referenceIds)
            ->selectRaw('COALESCE(SUM(ABS(quantity) * COALESCE(unit_cost,0)),0) as v')->value('v');
    }

    /** Skip documents whose every amount is zero (e.g. a free item with no cost). */
    private function postIfAny(array $header, array $lines): ?JournalEntry
    {
        $hasAmount = collect($lines)->contains(fn ($l) => ($l['debit'] ?? 0) > 0 || ($l['credit'] ?? 0) > 0);

        return $hasAmount ? $this->ledger->post($header, $lines) : null;
    }
}
