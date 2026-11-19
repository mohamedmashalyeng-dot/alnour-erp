<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\Core\Services\Settings;
use App\Modules\Core\Services\TaxCalculator;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Purchasing\Events\PurchaseReceived;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Purchase order lifecycle: draft -> ordered -> received, or draft|ordered -> cancelled.
 *
 * Receiving is the only step that touches inventory. It locks the PO row and requires
 * status "ordered", and every receipt line is a unique (reference, type) ledger entry, so
 * stock can never be increased twice for the same purchase.
 */
class PurchaseService
{
    public function __construct(
        private InventoryService $inventory,
        private BranchAccess $branches,
        private DocumentNumberGenerator $numbers,
        private TaxCalculator $tax,
        private Settings $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{supplier_id:int, branch_id:int, order_date:string, supplier_invoice_number?:string|null,
     *               supplier_invoice_date?:string|null, notes?:string|null, discount?:int,
     *               lines: list<array{product_id:int, quantity:int, unit_cost:int, discount?:int}>}  $data
     */
    public function saveDraft(array $data, ?PurchaseOrder $po = null): PurchaseOrder
    {
        Gate::authorize('purchases.create');
        $data = $this->validate($data, $po);
        $branch = $this->branches->authorizeOperational($data['branch_id']);

        return DB::transaction(function () use ($data, $po, $branch) {
            if ($po) {
                $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
                if ($po->status !== PurchaseStatus::Draft) {
                    throw ValidationException::withMessages(['status' => __('Only draft purchase orders can be edited.')]);
                }
                $this->branches->authorize($po->branch_id);
                $po->items()->delete();
            } else {
                $po = new PurchaseOrder;
                $po->number = $this->numbers->next('purchase', $branch->id, $branch->code);
                $po->status = PurchaseStatus::Draft;
                $po->payment_status = PaymentStatus::Unpaid;
                $po->created_by = auth()->id();
            }

            $po->fill([
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $branch->id,
                'order_date' => $data['order_date'],
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?: null,
                'supplier_invoice_date' => $data['supplier_invoice_date'] ?: null,
                'notes' => $data['notes'] ?: null,
            ]);

            $products = Product::whereIn('id', array_column($data['lines'], 'product_id'))->get()->keyBy('id');
            $calcLines = [];
            foreach ($data['lines'] as $line) {
                $calcLines[] = [
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_cost'],
                    'discount' => $line['discount'] ?? 0,
                    'tax_rate_bp' => $this->tax->rateFor($products[$line['product_id']]),
                ];
            }
            try {
                // Supplier prices are entered excluding tax.
                $calc = $this->tax->calculate($calcLines, $data['discount'] ?? 0, false);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['discount' => __('Discount exceeds the amount.')]);
            }

            $po->subtotal = $calc['subtotal'];
            $po->discount_total = $calc['discount_total'];
            $po->tax_total = $calc['tax_total'];
            $po->total = $calc['total'];
            $po->save();

            foreach ($calc['lines'] as $l) {
                $po->items()->create([
                    'product_id' => $l['product_id'],
                    'quantity' => $l['quantity'],
                    'unit_cost' => $l['unit_price'],
                    'discount_amount' => $l['discount'],
                    'tax_rate_bp' => $l['tax_rate_bp'],
                    'tax_amount' => $l['tax_amount'],
                    'line_total' => $l['line_total'],
                ]);
            }
            $this->audit->log($po->wasRecentlyCreated ? 'purchase.created' : 'purchase.updated', $po, [], ['total' => $po->total], $po->branch_id);

            return $po->load('items');
        });
    }

    public function confirm(PurchaseOrder $po): PurchaseOrder
    {
        Gate::authorize('purchases.create');
        $this->branches->authorize($po->branch_id);

        return DB::transaction(function () use ($po) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if ($locked->status !== PurchaseStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('Only draft purchase orders can be confirmed.')]);
            }
            if ($locked->items()->count() === 0) {
                throw ValidationException::withMessages(['lines' => __('Add at least one product.')]);
            }
            $locked->status = PurchaseStatus::Ordered;
            $locked->confirmed_by = auth()->id();
            $locked->confirmed_at = now();
            $locked->save();
            $this->audit->log('purchase.confirmed', $locked, ['status' => 'draft'], ['status' => 'ordered'], $locked->branch_id);

            return $locked;
        });
    }

    /**
     * Receive all goods into the PO's branch, update product costs, and post inventory transactions.
     */
    public function receive(PurchaseOrder $po): PurchaseOrder
    {
        Gate::authorize('purchases.receive');
        $this->branches->authorizeOperational($po->branch_id);

        return DB::transaction(function () use ($po) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->lockForUpdate()->with('items')->findOrFail($po->id);
            if ($locked->status !== PurchaseStatus::Ordered) {
                throw ValidationException::withMessages(['status' => __('This purchase order cannot be received in its current status (:status).', ['status' => $locked->status->label()])]);
            }

            foreach ($locked->items->sortBy('product_id') as $item) {
                $unitCost = $item->netUnitCost();
                $this->updateCost($item->product_id, $item->quantity, $unitCost);

                $this->inventory->move($locked->branch_id, $item->product_id, MovementType::PurchaseReceipt, $item->quantity, [
                    'reference' => $item,
                    'unit_cost' => $unitCost,
                    'reason' => __('Purchase :number', ['number' => $locked->number]),
                ]);
            }

            $locked->status = PurchaseStatus::Received;
            $locked->received_by = auth()->id();
            $locked->received_at = now();
            $locked->save();
            $this->audit->log('purchase.received', $locked, ['status' => 'ordered'], ['status' => 'received', 'total' => $locked->total], $locked->branch_id);
            event(new PurchaseReceived($locked));

            return $locked;
        });
    }

    public function cancel(PurchaseOrder $po, string $reason): PurchaseOrder
    {
        Gate::authorize('purchases.cancel');
        $this->branches->authorize($po->branch_id);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['cancel_reason' => __('A reason is required (max 255 characters).')]);
        }

        return DB::transaction(function () use ($po, $reason) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if (! in_array($locked->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered], true)) {
                throw ValidationException::withMessages(['status' => __('Received purchase orders cannot be cancelled.')]);
            }
            if ($locked->paid_amount > 0) {
                throw ValidationException::withMessages(['status' => __('This purchase order has payments and cannot be cancelled.')]);
            }
            $old = $locked->status->value;
            $locked->status = PurchaseStatus::Cancelled;
            $locked->cancelled_by = auth()->id();
            $locked->cancelled_at = now();
            $locked->cancel_reason = mb_substr($reason, 0, 255);
            $locked->save();
            $this->audit->log('purchase.cancelled', $locked, ['status' => $old], ['status' => 'cancelled', 'reason' => $reason], $locked->branch_id);

            return $locked;
        });
    }

    /**
     * Costing: weighted average over company-wide on-hand stock, or last purchase price.
     */
    private function updateCost(int $productId, int $receivedQty, int $unitCost): void
    {
        /** @var Product $product */
        $product = Product::query()->lockForUpdate()->findOrFail($productId);
        $method = $this->settings->get('costing_method', 'weighted_average');

        if ($method === 'last_purchase') {
            $newCost = $unitCost;
        } else {
            $onHand = max(0, (int) Inventory::where('product_id', $productId)->where('quantity', '>', 0)->sum('quantity'));
            $newCost = $onHand + $receivedQty > 0
                ? intdiv($onHand * $product->cost_price + $receivedQty * $unitCost + intdiv($onHand + $receivedQty, 2), $onHand + $receivedQty)
                : $unitCost;
        }

        $old = ['cost_price' => $product->cost_price, 'purchase_price' => $product->purchase_price];
        $product->cost_price = $newCost;
        $product->purchase_price = $unitCost;
        $product->save();
        $this->audit->log('product.cost_updated', $product, $old, ['cost_price' => $newCost, 'purchase_price' => $unitCost, 'method' => $method]);
    }

    private function validate(array $data, ?PurchaseOrder $po): array
    {
        return Validator::make($data, [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'order_date' => ['required', 'date'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:64',
                Rule::unique('purchase_orders', 'supplier_invoice_number')->where('supplier_id', $data['supplier_id'] ?? 0)->ignore($po?->id)],
            'supplier_invoice_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('track_stock', true)],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => ['required', 'integer', 'min:0'],
            'lines.*.discount' => ['nullable', 'integer', 'min:0'],
        ], [], ['supplier_invoice_number' => __('supplier invoice number')])->validate() + ['supplier_invoice_number' => null, 'supplier_invoice_date' => null, 'notes' => null];
    }
}
