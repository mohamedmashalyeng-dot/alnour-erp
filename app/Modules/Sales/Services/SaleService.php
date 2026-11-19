<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\Core\Services\Settings;
use App\Modules\Core\Services\TaxCalculator;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Optical\Models\Prescription;
use App\Modules\Optical\Services\LoyaltyService;
use App\Modules\Optical\Services\PrescriptionService;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Sales\Enums\SaleReturnStatus;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Events\SaleCancelled;
use App\Modules\Sales\Events\SaleCompleted;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Sale lifecycle. A completed sale:
 *  - gets a unique, sequential invoice number per branch/year;
 *  - deducts stock through InventoryService (row locks prevent concurrent overselling);
 *  - records its payments through PaymentService;
 *  - is idempotent on its client UUID (resubmitting the same cart never creates a second invoice).
 */
class SaleService
{
    public function __construct(
        private InventoryService $inventory,
        private PaymentService $payments,
        private BranchAccess $branches,
        private DocumentNumberGenerator $numbers,
        private TaxCalculator $tax,
        private Settings $settings,
        private AuditLogger $audit,
        private LoyaltyService $loyalty,
        private PrescriptionService $prescriptions,
    ) {}

    /**
     * @param  array{uuid:string, branch_id:int, customer_id?:int|null, notes?:string|null, discount?:int,
     *               items: list<array{product_id:int, quantity:int, discount?:int}>,
     *               payments?: list<array{method:string, amount:int, reference?:string|null, tendered?:int|null, sale_return_id?:int|null}>}  $data
     * @param  array{offline?:bool, device_id?:int, unit_prices?:array<int,int>, sold_at?:\DateTimeInterface, allow_negative?:bool}  $context
     *                                                                                                                                         INTERNAL ONLY (set by the offline sync engine, never from user input): the price actually charged
     *                                                                                                                                         on the device, the original sale time and the configured overselling policy.
     */
    public function complete(array $data, array $context = []): Sale
    {
        Gate::authorize('sales.create');
        $data = $this->validate($data);
        $branch = $this->branches->authorizeOperational($data['branch_id']);
        $user = auth()->user();

        if ($replay = $this->replay($data['uuid'], $user)) {
            return $replay;
        }

        try {
            $sale = DB::transaction(function () use ($data, $branch, $user, $context) {
                $customer = $this->resolveCustomer($data['customer_id'] ?? null);
                $products = Product::query()->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');

                // Price lines from the server-side catalogue; clients never set prices.
                $inclusive = $this->tax->pricesIncludeTax();
                $lines = [];
                foreach ($data['items'] as $item) {
                    $product = $products[$item['product_id']];
                    if (! $product->is_active) {
                        throw ValidationException::withMessages(['items' => __('Product :sku is inactive.', ['sku' => $product->sku])]);
                    }
                    $lines[] = [
                        'product_id' => $product->id,
                        'quantity' => $item['quantity'],
                        'unit_price' => (int) ($context['unit_prices'][$product->id] ?? $product->selling_price),
                        'discount' => $item['discount'] ?? 0,
                        'tax_rate_bp' => $this->tax->rateFor($product),
                    ];
                }
                $rx = $this->resolvePrescription(isset($data['prescription_id']) ? (int) $data['prescription_id'] : null, $customer, $products);

                // Loyalty redemption = a reward discount validated against the live points balance (not a cashier discount).
                $redeem = (int) ($data['redeem_points'] ?? 0);
                $loyaltyDiscount = 0;
                if ($redeem > 0) {
                    $net = 0;
                    foreach ($lines as $l) {
                        $net += $l['quantity'] * $l['unit_price'] - $l['discount'];
                    }
                    $loyaltyDiscount = $this->loyalty->quote($customer, $redeem, max(0, $net - (int) ($data['discount'] ?? 0)));
                }
                try {
                    $calc = $this->tax->calculate($lines, ($data['discount'] ?? 0) + $loyaltyDiscount, $inclusive);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(['discount' => __('Discount exceeds the amount.')]);
                }
                $this->authorizeDiscount($user, $calc['discount_total'] - $loyaltyDiscount, $calc['subtotal']);

                $payments = $data['payments'] ?? [];
                $paid = array_sum(array_column($payments, 'amount'));
                if ($paid > $calc['total']) {
                    throw ValidationException::withMessages(['payments' => __('Payments exceed the invoice total.')]);
                }
                if (! $customer && $paid < $calc['total']) {
                    throw ValidationException::withMessages(['customer_id' => __('Select a registered customer for credit or partial-payment sales.')]);
                }
                if (! $customer && ! $this->settings->bool('walk_in_customer_enabled')) {
                    throw ValidationException::withMessages(['customer_id' => __('A customer is required.')]);
                }

                // Resume a held draft with the same UUID, or start a new sale.
                $sale = Sale::query()->where('uuid', $data['uuid'])->lockForUpdate()->first();
                if ($sale) {
                    if ($sale->status !== SaleStatus::Draft || $sale->branch_id !== $branch->id) {
                        throw ValidationException::withMessages(['uuid' => __('This sale has already been processed.')]);
                    }
                    $sale->items()->delete();
                } else {
                    $sale = new Sale(['uuid' => $data['uuid']]);
                }

                $sale->fill([
                    'branch_id' => $branch->id,
                    'cashier_id' => $user->id,
                    'customer_id' => $customer?->id,
                    'notes' => $data['notes'] ?? null,
                ]);
                $sale->invoice_number = $this->numbers->next('sale', $branch->id, $branch->code);
                $sale->status = SaleStatus::Completed;
                $sale->payment_status = PaymentStatus::Unpaid;
                $sale->prices_include_tax = $inclusive;
                $sale->subtotal = $calc['subtotal'];
                $sale->discount_total = $calc['discount_total'];
                $sale->tax_total = $calc['tax_total'];
                $sale->total = $calc['total'];
                $sale->paid_amount = 0;
                $sale->sold_at = $context['sold_at'] ?? now();
                $sale->channel = ! empty($context['offline']) ? 'offline' : 'pos';
                $sale->device_id = $context['device_id'] ?? null;
                $sale->prescription_id = $rx?->id;
                $sale->loyalty_points_redeemed = $redeem;
                $sale->loyalty_discount = $loyaltyDiscount;
                $sale->save();
                if ($redeem > 0) {
                    $this->loyalty->redeem($sale, $customer, $redeem);
                }

                foreach ($calc['lines'] as $l) {
                    $product = $products[$l['product_id']];
                    $item = $sale->items()->create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'quantity' => $l['quantity'],
                        'unit_price' => $l['unit_price'],
                        'discount_amount' => $l['discount'],
                        'tax_rate_bp' => $l['tax_rate_bp'],
                        'tax_amount' => $l['tax_amount'],
                        'line_total' => $l['line_total'],
                        'unit_cost' => $product->cost_price,
                    ]);
                }

                // Deduct stock in product-id order (deterministic lock order avoids deadlocks).
                foreach ($sale->items()->orderBy('product_id')->get() as $item) {
                    if (! $products[$item->product_id]->track_stock) {
                        continue;
                    }
                    $this->inventory->move($branch->id, $item->product_id, MovementType::Sale, $item->quantity, [
                        'reference' => $item,
                        'unit_cost' => $item->unit_cost,
                        'reason' => __('Invoice :number', ['number' => $sale->invoice_number]),
                        'allow_negative' => (bool) ($context['allow_negative'] ?? false),
                    ]);
                }

                $this->payments->refreshSaleStatus($sale);
                $sale->save();

                foreach ($payments as $i => $p) {
                    if (! empty($context['offline'])) {
                        $p['offline'] = true;
                        $p['paid_at'] = $sale->sold_at;
                    }
                    $this->applyPayment($sale, $customer, $branch, $p, $data['uuid'], $i);
                }

                $sale->refresh();
                $this->audit->log('sale.completed', $sale, [], [
                    'invoice' => $sale->invoice_number, 'total' => $sale->total, 'paid' => $sale->paid_amount,
                ], $branch->id);

                // In-transaction: accounting posts atomically with the sale.
                event(new SaleCompleted($sale));

                return $sale;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same UUID won the race: return its result.
            if ($replay = $this->replay($data['uuid'], $user)) {
                return $replay;
            }
            throw $e;
        }

        return $sale;
    }

    /**
     * Save the cart as a draft (no stock or money effect). Drafts can be resumed in the POS.
     */
    public function saveDraft(array $data): Sale
    {
        Gate::authorize('sales.create');
        $data = $this->validate($data, draft: true);
        $branch = $this->branches->authorizeOperational($data['branch_id']);

        return DB::transaction(function () use ($data, $branch) {
            $customer = $this->resolveCustomer($data['customer_id'] ?? null);
            $sale = Sale::query()->where('uuid', $data['uuid'])->lockForUpdate()->first();
            if ($sale && $sale->status !== SaleStatus::Draft) {
                throw ValidationException::withMessages(['uuid' => __('This sale has already been processed.')]);
            }
            $sale ??= new Sale(['uuid' => $data['uuid']]);
            $sale->fill(['branch_id' => $branch->id, 'cashier_id' => auth()->id(), 'customer_id' => $customer?->id, 'notes' => $data['notes'] ?? null]);
            $sale->status = SaleStatus::Draft;
            $sale->payment_status = PaymentStatus::Unpaid;
            $sale->save();
            $sale->items()->delete();

            $products = Product::whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $p = $products[$item['product_id']];
                $lineTotal = $item['quantity'] * $p->selling_price;
                $subtotal += $lineTotal;
                $sale->items()->create([
                    'product_id' => $p->id, 'product_name' => $p->name, 'sku' => $p->sku, 'quantity' => $item['quantity'],
                    'unit_price' => $p->selling_price, 'discount_amount' => $item['discount'] ?? 0, 'line_total' => $lineTotal, 'unit_cost' => $p->cost_price,
                ]);
            }
            $sale->subtotal = $subtotal;
            $sale->total = $subtotal;
            $sale->save();

            return $sale;
        });
    }

    public function discardDraft(Sale $sale): void
    {
        Gate::authorize('sales.create');
        $this->branches->authorize($sale->branch_id);
        DB::transaction(function () use ($sale) {
            $locked = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            if ($locked->status !== SaleStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('Only drafts can be discarded.')]);
            }
            $locked->items()->delete();
            $locked->delete();
        });
    }

    /**
     * Cancel a completed sale: stock is returned via reversal movements and any net payment is refunded.
     * The sale record itself is kept (status = cancelled).
     */
    public function cancel(Sale $sale, string $reason, PaymentMethod $refundMethod = PaymentMethod::Cash): Sale
    {
        Gate::authorize('sales.cancel');
        $this->branches->authorizeOperational($sale->branch_id);
        if (trim($reason) === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['cancel_reason' => __('A reason is required (max 255 characters).')]);
        }

        $result = DB::transaction(function () use ($sale, $reason, $refundMethod) {
            /** @var Sale $locked */
            $locked = Sale::query()->lockForUpdate()->with('items.product')->findOrFail($sale->id);
            if (! in_array($locked->status, [SaleStatus::Completed, SaleStatus::PartiallyPaid], true)) {
                throw ValidationException::withMessages(['status' => __('This sale cannot be cancelled in its current status.')]);
            }
            if ($locked->return_status !== 'none' || SaleReturn::where('sale_id', $locked->id)->where('status', '!=', SaleReturnStatus::Rejected->value)->exists()) {
                throw ValidationException::withMessages(['status' => __('Sales with returns cannot be cancelled. Use the returns process instead.')]);
            }

            foreach ($locked->items->sortBy('product_id') as $item) {
                $posted = InventoryTransaction::where('reference_type', $item->getMorphClass())
                    ->where('reference_id', $item->id)->where('type', MovementType::Sale->value)->exists();
                if ($posted) {
                    $this->inventory->move($locked->branch_id, $item->product_id, MovementType::SaleCancel, $item->quantity, [
                        'reference' => $item,
                        'unit_cost' => $item->unit_cost,
                        'reason' => __('Cancellation of :number', ['number' => $locked->invoice_number]),
                    ]);
                }
            }

            $netPaid = $locked->paid_amount - $locked->refunded_amount;
            if ($netPaid > 0) {
                $this->payments->refundToCustomer($locked, $locked->branch, $refundMethod, $netPaid, [
                    'notes' => __('Refund for cancelled invoice :number', ['number' => $locked->invoice_number]),
                ], allowCancelledSale: true);
                $locked->refresh();
            }

            $locked->status = SaleStatus::Cancelled;
            $locked->cancelled_by = auth()->id();
            $locked->cancelled_at = now();
            $locked->cancel_reason = $reason;
            $locked->save();
            $this->audit->log('sale.cancelled', $locked, [], ['reason' => $reason, 'refunded' => $netPaid], $locked->branch_id);
            event(new SaleCancelled($locked));

            return $locked;
        });

        return $result;
    }

    /**
     * Additional payment on an existing invoice (e.g. customer settles the balance later).
     */
    public function addPayment(Sale $sale, array $payment): Sale
    {
        Gate::authorize('payments.record');
        $this->branches->authorizeOperational($sale->branch_id);

        return DB::transaction(function () use ($sale, $payment) {
            $sale = Sale::with('customer', 'branch')->findOrFail($sale->id);
            $this->applyPayment($sale, $sale->customer, $sale->branch, $payment, $payment['uuid'] ?? (string) Str::uuid(), 0);

            return $sale->refresh();
        });
    }

    // ------------------------------------------------------------------

    private function applyPayment(Sale $sale, ?Customer $customer, Branch $branch, array $p, string $uuidSeed, int $index): void
    {
        $method = PaymentMethod::from($p['method']);
        $meta = [
            // Deterministic per-line key: replaying the same sale can never duplicate its payments.
            'uuid' => $p['uuid'] ?? self::derivedUuid($uuidSeed, 'payment-'.$index),
            'reference' => $p['reference'] ?? null,
            'tendered' => $p['tendered'] ?? null,
            'notes' => $p['notes'] ?? null,
            'offline' => $p['offline'] ?? false,
            'paid_at' => $p['paid_at'] ?? null,
        ];

        if ($method === PaymentMethod::StoreCredit) {
            $this->applyStoreCredit($sale, $customer, $branch, (int) $p['amount'], (int) ($p['sale_return_id'] ?? 0), $meta);

            return;
        }

        $this->payments->receiveFromCustomer($customer, $branch, $method, [$sale->id => (int) $p['amount']], $meta);
    }

    /**
     * Exchange: credit from a completed return is moved (non-cash) from the original sale to this sale.
     */
    private function applyStoreCredit(Sale $sale, ?Customer $customer, Branch $branch, int $amount, int $returnId, array $meta): void
    {
        Gate::authorize('refunds.process');
        /** @var SaleReturn $return */
        $return = SaleReturn::query()->lockForUpdate()->find($returnId)
            ?? throw ValidationException::withMessages(['payments' => __('Return not found.')]);
        if ($return->status !== SaleReturnStatus::Completed) {
            throw ValidationException::withMessages(['payments' => __('The return must be completed before its credit can be used.')]);
        }
        $this->branches->authorize($return->branch_id);
        if ($return->customer_id !== $customer?->id) {
            throw ValidationException::withMessages(['payments' => __('The return belongs to a different customer.')]);
        }
        $available = $return->total - $return->refunded_amount - $return->exchange_credit_used;
        if ($amount <= 0 || $amount > $available) {
            throw ValidationException::withMessages(['payments' => __('Store credit available on this return: :amount.', ['amount' => money(max(0, $available))])]);
        }

        $original = Sale::findOrFail($return->sale_id);
        $this->payments->refundToCustomer($original, $branch, PaymentMethod::StoreCredit, $amount, [
            'uuid' => self::derivedUuid($meta['uuid'], 'store-credit-out'),
            'sale_return_id' => $return->id,
            'notes' => __('Exchange credit applied to :number', ['number' => $sale->invoice_number]),
        ]);
        $this->payments->receiveFromCustomer($customer, $branch, PaymentMethod::StoreCredit, [$sale->id => $amount], $meta + ['sale_return_id' => $return->id]);

        $return->refresh();
        $return->exchange_sale_id = $sale->id;
        $return->save();
    }

    public static function derivedUuid(string $seed, string $purpose): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, $seed.'|'.$purpose)->toString();
    }

    private function authorizeDiscount(User $user, int $discount, int $subtotal): void
    {
        if ($discount <= 0) {
            return;
        }
        if (! $user->can('sales.discount')) {
            throw ValidationException::withMessages(['discount' => __('You are not allowed to give discounts.')]);
        }
        $max = (int) $this->settings->get('max_discount_percent', 0);
        if (! $user->can('sales.discount-unlimited') && $discount * 100 > $subtotal * $max) {
            throw ValidationException::withMessages(['discount' => __('Discount exceeds your limit of :max%.', ['max' => $max])]);
        }
    }

    /** The prescription must belong to the customer, be active and unexpired, and fit every lens on the invoice. */
    private function resolvePrescription(?int $id, ?Customer $customer, $products): ?Prescription
    {
        if (! $id) {
            return null;
        }
        Gate::authorize('prescriptions.view');
        $rx = Prescription::find($id);
        if (! $rx || ! $customer || $rx->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['prescription_id' => __('The prescription does not belong to this customer.')]);
        }
        if (! $rx->isUsable()) {
            throw ValidationException::withMessages(['prescription_id' => __('The prescription is void or expired.')]);
        }
        foreach ($products as $product) {
            if ($problems = $this->prescriptions->lensProblems($rx, $product)) {
                throw ValidationException::withMessages(['prescription_id' => $product->sku.': '.implode(' ', $problems)]);
            }
        }

        return $rx;
    }

    private function resolveCustomer(?int $id): ?Customer
    {
        if (! $id) {
            return null;
        }
        $customer = Customer::find($id);
        if (! $customer || ! $customer->is_active) {
            throw ValidationException::withMessages(['customer_id' => __('Invalid customer.')]);
        }

        return $customer;
    }

    private function replay(string $uuid, User $user): ?Sale
    {
        $existing = Sale::where('uuid', $uuid)->first();
        if (! $existing || $existing->status === SaleStatus::Draft) {
            return null;
        }
        $this->branches->authorize($existing->branch_id, $user);

        return $existing;
    }

    private function validate(array $data, bool $draft = false): array
    {
        return Validator::make($data, [
            'uuid' => ['required', 'uuid'],
            'branch_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.discount' => ['nullable', 'integer', 'min:0'],
            'payments' => [$draft ? 'nullable' : 'present', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::in(PaymentMethod::values())],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'payments.*.tendered' => ['nullable', 'integer', 'min:0'],
            'payments.*.sale_return_id' => ['nullable', 'integer'],
            'redeem_points' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'prescription_id' => ['nullable', 'integer'],
        ])->validate();
    }
}
