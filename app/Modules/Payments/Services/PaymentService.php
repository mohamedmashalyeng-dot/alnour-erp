<?php

namespace App\Modules\Payments\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\Customers\Models\Customer;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentRecorded;
use App\Modules\Payments\Models\Payment;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records payments and keeps document paid/refunded amounts in sync.
 *
 * Duplicate protection:
 *  - every payment carries a client-generated UUID (unique index). Replaying the same UUID
 *    returns the original payment instead of creating a second one;
 *  - documents are locked FOR UPDATE and amounts are validated against the live balance,
 *    so concurrent double-submits cannot over-pay.
 *
 * Callers are responsible for permission checks (they differ per context: POS vs back office).
 */
class PaymentService
{
    public function __construct(
        private DocumentNumberGenerator $numbers,
        private AuditLogger $audit,
        private TreasuryService $treasury,
    ) {}

    /**
     * Customer payment allocated to specific sales. Must be called inside a transaction
     * when composed with other writes (e.g. sale completion); safe standalone too.
     *
     * @param  array<int,int>  $allocations  sale_id => amount
     */
    public function receiveFromCustomer(?Customer $customer, Branch $branch, PaymentMethod $method, array $allocations, array $meta = []): Payment
    {
        return DB::transaction(function () use ($customer, $branch, $method, $allocations, $meta) {
            if ($existing = $this->replay($meta['uuid'] ?? null)) {
                return $existing;
            }
            $amount = $this->validateAllocations($allocations);

            $sales = Sale::query()->whereIn('id', array_keys($allocations))->lockForUpdate()->get()->keyBy('id');
            foreach ($allocations as $saleId => $value) {
                $sale = $sales->get($saleId) ?? throw ValidationException::withMessages(['amount' => __('Invoice not found.')]);
                if ($sale->customer_id !== $customer?->id) {
                    throw ValidationException::withMessages(['amount' => __('The invoice does not belong to this customer.')]);
                }
                if (in_array($sale->status, [SaleStatus::Draft, SaleStatus::Cancelled], true)) {
                    throw ValidationException::withMessages(['amount' => __('Payments cannot be recorded on this invoice.')]);
                }
                if ($value > $sale->balanceDue()) {
                    throw ValidationException::withMessages(['amount' => __('Payment exceeds the remaining balance of :ref (:due).', [
                        'ref' => $sale->invoice_number, 'due' => money(max(0, $sale->balanceDue())),
                    ])]);
                }
            }

            if ($method === PaymentMethod::StoreCredit && empty($meta['sale_return_id'])) {
                throw ValidationException::withMessages(['method' => __('Store credit must reference a return.')]);
            }

            $payment = $this->createPayment(Payment::IN, 'customer', $customer?->id, null, $branch, $method, $amount, $meta);

            foreach ($allocations as $saleId => $value) {
                $sale = $sales->get($saleId);
                $payment->allocations()->create(['allocatable_type' => $sale->getMorphClass(), 'allocatable_id' => $sale->id, 'amount' => $value]);
                $sale->paid_amount += $value;
                $this->refreshSaleStatus($sale);
                $sale->save();
            }
            $this->audit->log('payment.received', $payment, [], ['amount' => $amount, 'method' => $method->value, 'allocations' => $allocations], $branch->id);

            return $payment;
        });
    }

    /**
     * Pay money back to a customer against a sale (after a return or cancellation).
     */
    public function refundToCustomer(Sale $sale, Branch $branch, PaymentMethod $method, int $amount, array $meta = [], bool $allowCancelledSale = false): Payment
    {
        return DB::transaction(function () use ($sale, $branch, $method, $amount, $meta, $allowCancelledSale) {
            if ($existing = $this->replay($meta['uuid'] ?? null)) {
                return $existing;
            }
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('Amount must be greater than zero.')]);
            }
            /** @var Sale $locked */
            $locked = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $refundable = $allowCancelledSale ? $locked->paid_amount - $locked->refunded_amount : $locked->refundable();
            if ($amount > $refundable) {
                throw ValidationException::withMessages(['amount' => __('Refund exceeds the refundable amount (:max).', ['max' => money(max(0, $refundable))])]);
            }

            $payment = $this->createPayment(Payment::OUT, 'customer', $locked->customer_id, null, $branch, $method, $amount, $meta);
            $payment->allocations()->create(['allocatable_type' => $locked->getMorphClass(), 'allocatable_id' => $locked->id, 'amount' => $amount]);

            $locked->refunded_amount += $amount;
            if (! $allowCancelledSale) {
                $this->refreshSaleStatus($locked);
            }
            $locked->save();

            if (! empty($meta['sale_return_id'])) {
                $return = SaleReturn::query()->lockForUpdate()->findOrFail($meta['sale_return_id']);
                if ($method === PaymentMethod::StoreCredit) {
                    $return->exchange_credit_used += $amount;
                } else {
                    $return->refunded_amount += $amount;
                }
                $return->save();
            }
            $this->audit->log('payment.refunded', $payment, [], ['amount' => $amount, 'method' => $method->value, 'sale' => $locked->invoice_number], $branch->id);

            $sale->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });
    }

    /**
     * Customer payment on account, allocated oldest-invoice-first.
     */
    public function receiveOnAccount(Customer $customer, Branch $branch, PaymentMethod $method, int $amount, array $meta = []): Payment
    {
        return DB::transaction(function () use ($customer, $branch, $method, $amount, $meta) {
            if ($existing = $this->replay($meta['uuid'] ?? null)) {
                return $existing;
            }
            $open = Sale::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', [SaleStatus::Completed->value, SaleStatus::PartiallyPaid->value])
                ->orderBy('sold_at')->orderBy('id')
                ->lockForUpdate()->get()
                ->filter(fn (Sale $s) => $s->balanceDue() > 0);

            return $this->receiveFromCustomer($customer, $branch, $method, $this->fifo($open, $amount, fn (Sale $s) => $s->balanceDue()), $meta);
        });
    }

    /**
     * Supplier payment allocated to specific purchase orders.
     *
     * @param  array<int,int>  $allocations  purchase_order_id => amount
     */
    public function paySupplier(Supplier $supplier, Branch $branch, PaymentMethod $method, array $allocations, array $meta = []): Payment
    {
        return DB::transaction(function () use ($supplier, $branch, $method, $allocations, $meta) {
            if ($existing = $this->replay($meta['uuid'] ?? null)) {
                return $existing;
            }
            if ($method === PaymentMethod::StoreCredit) {
                throw ValidationException::withMessages(['method' => __('Invalid payment method.')]);
            }
            $amount = $this->validateAllocations($allocations);
            $orders = PurchaseOrder::query()->whereIn('id', array_keys($allocations))->lockForUpdate()->get()->keyBy('id');

            foreach ($allocations as $id => $value) {
                $po = $orders->get($id) ?? throw ValidationException::withMessages(['amount' => __('Purchase order not found.')]);
                if ($po->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages(['amount' => __('The purchase order does not belong to this supplier.')]);
                }
                if (! in_array($po->status, [PurchaseStatus::Ordered, PurchaseStatus::Received], true)) {
                    throw ValidationException::withMessages(['amount' => __('Payments can only be recorded on confirmed purchase orders.')]);
                }
                if ($value > $po->balanceDue()) {
                    throw ValidationException::withMessages(['amount' => __('Payment exceeds the remaining balance of :ref (:due).', ['ref' => $po->number, 'due' => money($po->balanceDue())])]);
                }
            }

            $payment = $this->createPayment(Payment::OUT, 'supplier', null, $supplier->id, $branch, $method, $amount, $meta);
            foreach ($allocations as $id => $value) {
                $po = $orders->get($id);
                $payment->allocations()->create(['allocatable_type' => $po->getMorphClass(), 'allocatable_id' => $po->id, 'amount' => $value]);
                $po->paid_amount += $value;
                $po->payment_status = $this->paymentStatus($po->paid_amount, $po->total);
                $po->save();
            }
            $this->audit->log('payment.supplier_paid', $payment, [], ['amount' => $amount, 'method' => $method->value, 'allocations' => $allocations], $branch->id);

            return $payment;
        });
    }

    public function paySupplierOnAccount(Supplier $supplier, Branch $branch, PaymentMethod $method, int $amount, array $meta = []): Payment
    {
        return DB::transaction(function () use ($supplier, $branch, $method, $amount, $meta) {
            if ($existing = $this->replay($meta['uuid'] ?? null)) {
                return $existing;
            }
            $open = PurchaseOrder::query()
                ->where('supplier_id', $supplier->id)
                ->whereIn('status', [PurchaseStatus::Received->value, PurchaseStatus::Ordered->value])
                ->orderByRaw("CASE WHEN status = 'received' THEN 0 ELSE 1 END")
                ->orderBy('order_date')->orderBy('id')
                ->lockForUpdate()->get()
                ->filter(fn (PurchaseOrder $po) => $po->balanceDue() > 0);

            return $this->paySupplier($supplier, $branch, $method, $this->fifo($open, $amount, fn (PurchaseOrder $po) => $po->balanceDue()), $meta);
        });
    }

    public function refreshSaleStatus(Sale $sale): void
    {
        if (in_array($sale->status, [SaleStatus::Draft, SaleStatus::Cancelled], true)) {
            return;
        }
        $net = $sale->netTotal();
        $paidNet = $sale->paid_amount - $sale->refunded_amount;
        $sale->payment_status = $this->paymentStatus($paidNet, $net);

        if ($sale->return_status === 'full' && $sale->balanceDue() === 0) {
            $sale->status = SaleStatus::Refunded;
        } elseif ($sale->balanceDue() > 0) {
            $sale->status = SaleStatus::PartiallyPaid;
        } else {
            $sale->status = $sale->return_status === 'full' ? SaleStatus::Refunded : SaleStatus::Completed;
        }
    }

    public function paymentStatus(int $paid, int $total): PaymentStatus
    {
        return match (true) {
            $paid >= $total => PaymentStatus::Paid,
            $paid > 0 => PaymentStatus::Partial,
            default => PaymentStatus::Unpaid,
        };
    }

    // ------------------------------------------------------------------

    private function replay(?string $uuid): ?Payment
    {
        return $uuid ? Payment::where('uuid', $uuid)->first() : null;
    }

    private function validateAllocations(array $allocations): int
    {
        if ($allocations === []) {
            throw ValidationException::withMessages(['amount' => __('There is no outstanding balance to pay.')]);
        }
        $sum = 0;
        foreach ($allocations as $value) {
            if (! is_int($value) || $value <= 0) {
                throw ValidationException::withMessages(['amount' => __('Amount must be greater than zero.')]);
            }
            $sum += $value;
        }

        return $sum;
    }

    /**
     * @return array<int,int>
     */
    private function fifo(iterable $documents, int $amount, \Closure $due): array
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('Amount must be greater than zero.')]);
        }
        $allocations = [];
        $left = $amount;
        foreach ($documents as $doc) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $due($doc));
            $allocations[$doc->id] = $take;
            $left -= $take;
        }
        if ($left > 0) {
            throw ValidationException::withMessages(['amount' => __('Payment exceeds the total outstanding balance.')]);
        }

        return $allocations;
    }

    private function createPayment(string $direction, string $partyType, ?int $customerId, ?int $supplierId, Branch $branch, PaymentMethod $method, int $amount, array $meta): Payment
    {
        $tendered = $meta['tendered'] ?? null;
        $change = null;
        if ($method === PaymentMethod::Cash && $tendered !== null) {
            if ($tendered < $amount) {
                throw ValidationException::withMessages(['tendered' => __('Cash tendered is less than the amount.')]);
            }
            $change = $tendered - $amount;
        }

        // Where the money physically goes (cash drawer / shift, bank account). Enforces open cash shifts.
        $route = $this->treasury->route($branch, $method, $direction, $partyType, $meta);

        $payment = Payment::create($route + [
            'uuid' => $meta['uuid'] ?? (string) Str::uuid(),
            'number' => $this->numbers->next('payment', $branch->id, $branch->code),
            'direction' => $direction,
            'party_type' => $partyType,
            'customer_id' => $customerId,
            'supplier_id' => $supplierId,
            'branch_id' => $branch->id,
            'user_id' => auth()->id(),
            'method' => $method,
            'amount' => $amount,
            'tendered_amount' => $method === PaymentMethod::Cash ? $tendered : null,
            'change_amount' => $change,
            'reference' => isset($meta['reference']) ? mb_substr((string) $meta['reference'], 0, 100) : null,
            'status' => 'posted',
            'sale_return_id' => $meta['sale_return_id'] ?? null,
            'notes' => $meta['notes'] ?? null,
            'paid_at' => $meta['paid_at'] ?? now(),
        ]);

        // Dispatched inside the caller's transaction: the journal entry commits (or rolls back) with the payment.
        event(new PaymentRecorded($payment));

        return $payment;
    }
}
