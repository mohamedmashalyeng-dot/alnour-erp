<?php

namespace App\Modules\Sales\Services;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\Core\Support\Money;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Sales\Enums\ItemCondition;
use App\Modules\Sales\Enums\ReturnDisposition;
use App\Modules\Sales\Enums\SaleReturnStatus;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Events\SaleReturnCompleted;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Customer returns are separate documents linked to the original sale (which is never modified
 * except for its returned/refunded aggregates).
 *
 *   create   -> pending: quantities are reserved against the sale lines (cannot be returned twice)
 *   complete -> physical condition confirmed; "restock" lines go back into inventory;
 *               the credit is applied to the sale and may be refunded or used for an exchange
 *   reject   -> reserved quantities are released
 */
class ReturnService
{
    public function __construct(
        private InventoryService $inventory,
        private PaymentService $payments,
        private BranchAccess $branches,
        private DocumentNumberGenerator $numbers,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{sale_item_id:int, quantity:int, condition:string, disposition:string}>  $lines
     */
    public function create(Sale $sale, array $lines, string $reason, string $type = 'return', ?string $notes = null, ?string $uuid = null): SaleReturn
    {
        Gate::authorize('returns.create');
        $this->branches->authorizeOperational($sale->branch_id);
        Validator::make(compact('lines', 'reason', 'type', 'notes'), [
            'reason' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['return', 'exchange'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.condition' => ['required', Rule::in(ItemCondition::values())],
            'lines.*.disposition' => ['required', Rule::in(ReturnDisposition::values())],
        ])->validate();

        $uuid ??= (string) Str::uuid();
        if ($existing = SaleReturn::where('uuid', $uuid)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($sale, $lines, $reason, $type, $notes, $uuid) {
            /** @var Sale $locked */
            $locked = Sale::query()->lockForUpdate()->with('branch')->findOrFail($sale->id);
            if (! in_array($locked->status, [SaleStatus::Completed, SaleStatus::PartiallyPaid, SaleStatus::Refunded], true)) {
                throw ValidationException::withMessages(['sale' => __('Returns are only possible for completed invoices.')]);
            }

            $items = SaleItem::query()->where('sale_id', $locked->id)->lockForUpdate()->get()->keyBy('id');
            $return = new SaleReturn([
                'uuid' => $uuid,
                'number' => $this->numbers->next('return', $locked->branch_id, $locked->branch->code),
                'sale_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'customer_id' => $locked->customer_id,
                'user_id' => auth()->id(),
                'type' => $type,
                'reason' => $reason,
                'notes' => $notes,
            ]);
            $return->status = SaleReturnStatus::Pending;
            $return->save();

            $total = 0;
            $tax = 0;
            foreach ($lines as $i => $line) {
                $item = $items->get($line['sale_item_id']) ?? throw ValidationException::withMessages(["lines.$i.sale_item_id" => __('Item does not belong to this invoice.')]);
                if ($line['quantity'] > $item->returnableQuantity()) {
                    throw ValidationException::withMessages(["lines.$i.quantity" => __('Only :qty unit(s) of :sku can still be returned.', ['qty' => $item->returnableQuantity(), 'sku' => $item->sku])]);
                }
                [$amount, $lineTax] = $this->creditFor($item, $line['quantity']);

                $return->items()->create([
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $line['quantity'],
                    'amount' => $amount,
                    'tax_amount' => $lineTax,
                    'condition' => $line['condition'],
                    'disposition' => $line['disposition'],
                ]);
                $item->returned_quantity += $line['quantity'];
                $item->save();
                $total += $amount;
                $tax += $lineTax;
            }

            $return->subtotal = $total - $tax;
            $return->tax_total = $tax;
            $return->total = $total;
            $return->save();
            $this->audit->log('return.created', $return, [], ['sale' => $locked->invoice_number, 'total' => $total], $return->branch_id);

            return $return->load('items');
        });
    }

    /**
     * Confirm inspection: restock eligible items and apply the credit to the sale.
     *
     * @param  array<int,string>  $dispositions  sale_return_item_id => disposition (optional override after inspection)
     */
    public function complete(SaleReturn $return, array $dispositions = []): SaleReturn
    {
        Gate::authorize('returns.approve');
        $this->branches->authorizeOperational($return->branch_id);

        $result = DB::transaction(function () use ($return, $dispositions) {
            /** @var SaleReturn $locked */
            $locked = SaleReturn::query()->lockForUpdate()->with('items')->findOrFail($return->id);
            if ($locked->status !== SaleReturnStatus::Pending) {
                throw ValidationException::withMessages(['status' => __('This return has already been processed.')]);
            }

            foreach ($locked->items->sortBy('product_id') as $item) {
                if (isset($dispositions[$item->id])) {
                    $item->disposition = ReturnDisposition::from($dispositions[$item->id]);
                }
                if ($item->disposition === ReturnDisposition::Restock) {
                    $saleItem = SaleItem::find($item->sale_item_id);
                    $this->inventory->move($locked->branch_id, $item->product_id, MovementType::SaleReturn, $item->quantity, [
                        'reference' => $item,
                        'unit_cost' => $saleItem?->unit_cost,
                        'reason' => __('Return :number', ['number' => $locked->number]),
                    ]);
                    $item->restocked = true;
                }
                $item->save();
            }

            /** @var Sale $sale */
            $sale = Sale::query()->lockForUpdate()->with('items')->findOrFail($locked->sale_id);
            $sale->returned_amount += $locked->total;
            $allReturned = $sale->items->every(fn (SaleItem $i) => $i->returned_quantity >= $i->quantity);
            $completedQty = SaleReturnItem::query()
                ->whereIn('sale_item_id', $sale->items->pluck('id'))
                ->whereHas('saleReturn', fn ($q) => $q->where('status', SaleReturnStatus::Completed->value)->orWhere('id', $locked->id))
                ->sum('quantity');
            $sale->return_status = ($allReturned && $completedQty >= $sale->items->sum('quantity')) ? 'full' : 'partial';
            $this->payments->refreshSaleStatus($sale);
            $sale->save();

            $locked->status = SaleReturnStatus::Completed;
            $locked->inspected_by = auth()->id();
            $locked->inspected_at = now();
            $locked->save();
            $this->audit->log('return.completed', $locked, [], ['total' => $locked->total, 'sale' => $sale->invoice_number], $locked->branch_id);
            event(new SaleReturnCompleted($locked));

            return $locked;
        });

        return $result;
    }

    public function reject(SaleReturn $return, string $reason): SaleReturn
    {
        Gate::authorize('returns.approve');
        $this->branches->authorize($return->branch_id);

        return DB::transaction(function () use ($return, $reason) {
            $locked = SaleReturn::query()->lockForUpdate()->with('items')->findOrFail($return->id);
            if ($locked->status !== SaleReturnStatus::Pending) {
                throw ValidationException::withMessages(['status' => __('This return has already been processed.')]);
            }
            foreach ($locked->items as $item) {
                $saleItem = SaleItem::query()->lockForUpdate()->findOrFail($item->sale_item_id);
                $saleItem->returned_quantity = max(0, $saleItem->returned_quantity - $item->quantity);
                $saleItem->save();
            }
            $locked->status = SaleReturnStatus::Rejected;
            $locked->inspected_by = auth()->id();
            $locked->inspected_at = now();
            $locked->notes = trim(($locked->notes ? $locked->notes."\n" : '').__('Rejected: :reason', ['reason' => $reason]));
            $locked->save();
            $this->audit->log('return.rejected', $locked, [], ['reason' => $reason], $locked->branch_id);

            return $locked;
        });
    }

    /**
     * Refund (part of) a completed return's credit to the customer.
     */
    public function refund(SaleReturn $return, PaymentMethod $method, int $amount, ?string $reference = null, ?string $uuid = null): Payment
    {
        Gate::authorize('refunds.process');
        $branch = $this->branches->authorizeOperational($return->branch_id);
        if ($method === PaymentMethod::StoreCredit) {
            throw ValidationException::withMessages(['method' => __('Use the POS to apply store credit to a new sale.')]);
        }

        // Idempotent replay (double-click / retry) returns the original refund before any re-validation.
        if ($uuid && ($existing = Payment::where('uuid', $uuid)->first())) {
            return $existing;
        }

        return DB::transaction(function () use ($return, $method, $amount, $reference, $uuid, $branch) {
            $locked = SaleReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status !== SaleReturnStatus::Completed) {
                throw ValidationException::withMessages(['status' => __('Only completed returns can be refunded.')]);
            }
            $available = $locked->total - $locked->refunded_amount - $locked->exchange_credit_used;
            if ($amount <= 0 || $amount > $available) {
                throw ValidationException::withMessages(['amount' => __('Refund exceeds the refundable amount (:max).', ['max' => money(max(0, $available))])]);
            }

            return $this->payments->refundToCustomer(Sale::findOrFail($locked->sale_id), $branch, $method, $amount, [
                'uuid' => $uuid,
                'reference' => $reference,
                'sale_return_id' => $locked->id,
            ]);
        });
    }

    /**
     * Credit for returning $qty units of a sale line, pro-rata to what the customer actually paid
     * for that line (after discounts, incl. tax). The final units absorb any rounding remainder.
     *
     * @return array{0:int,1:int} [amount, tax]
     */
    public function creditFor(SaleItem $item, int $qty): array
    {
        $previous = SaleReturnItem::query()
            ->where('sale_item_id', $item->id)
            ->whereHas('saleReturn', fn ($q) => $q->where('status', '!=', SaleReturnStatus::Rejected->value))
            ->selectRaw('COALESCE(SUM(quantity),0) as qty, COALESCE(SUM(amount),0) as amount, COALESCE(SUM(tax_amount),0) as tax')
            ->first();

        if ((int) $previous->qty + $qty >= $item->quantity) {
            return [$item->line_total - (int) $previous->amount, $item->tax_amount - (int) $previous->tax];
        }

        return [
            Money::divRound($item->line_total * $qty, $item->quantity),
            Money::divRound($item->tax_amount * $qty, $item->quantity),
        ];
    }
}
