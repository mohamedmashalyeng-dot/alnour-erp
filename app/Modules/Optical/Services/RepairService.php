<?php

namespace App\Modules\Optical\Services;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\Core\Services\Settings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Optical\Models\RepairOrder;
use App\Modules\Optical\Models\WarrantyClaim;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Repair orders. A charged repair is invoiced at delivery through the normal SaleService (service product,
 * price set by the authorized user), so tax, payments, cash shifts and accounting all follow the sale rules.
 * A repair covered by an approved warranty claim (resolution = repair) is delivered free of charge.
 */
class RepairService
{
    public function __construct(
        private BranchAccess $branches,
        private DocumentNumberGenerator $numbers,
        private AuditLogger $audit,
        private Settings $settings,
        private SaleService $sales,
    ) {}

    public function create(array $data): RepairOrder
    {
        Gate::authorize('repairs.manage');
        $data = Validator::make($data, [
            'branch_id' => ['required', 'integer'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'warranty_claim_id' => ['nullable', 'integer', 'exists:warranty_claims,id'],
            'item_description' => ['required', 'string', 'max:255'],
            'problem' => ['required', 'string', 'max:2000'],
            'estimated_cost' => ['required', 'integer', 'min:0'],
            'promised_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $branch = $this->branches->authorizeOperational((int) $data['branch_id']);
        $customer = Customer::findOrFail($data['customer_id']);
        if ($customer->is_walk_in) {
            throw ValidationException::withMessages(['customer_id' => __('Repairs need a registered customer (to contact them).')]);
        }
        if (! empty($data['warranty_claim_id'])) {
            $claim = WarrantyClaim::with('warranty')->findOrFail($data['warranty_claim_id']);
            if ($claim->status !== 'approved' || $claim->resolution !== 'repair' || $claim->warranty->customer_id !== $customer->id) {
                throw ValidationException::withMessages(['warranty_claim_id' => __('The claim must be approved for repair and belong to this customer.')]);
            }
            $data['estimated_cost'] = 0;
        }

        return DB::transaction(function () use ($data, $branch) {
            $order = RepairOrder::create($data + ['number' => $this->numbers->next('repair', $branch->id, $branch->code), 'status' => 'received', 'received_by' => auth()->id()]);
            $this->audit->log('repair.created', $order, [], ['number' => $order->number], $branch->id);

            return $order;
        });
    }

    public function setStatus(RepairOrder $order, string $status): RepairOrder
    {
        Gate::authorize('repairs.manage');
        $this->branches->authorizeOperational($order->branch_id);

        return DB::transaction(function () use ($order, $status) {
            $order = RepairOrder::lockForUpdate()->findOrFail($order->id);
            if (! in_array($status, RepairOrder::TRANSITIONS[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('Cannot move a repair from :from to :to.', ['from' => __('repair.'.$order->status), 'to' => __('repair.'.$status)])]);
            }
            $old = $order->status;
            $order->update(['status' => $status]);
            $this->audit->log('repair.status', $order, ['status' => $old], ['status' => $status], $order->branch_id);

            return $order;
        });
    }

    /**
     * Deliver to the customer. $finalCost (minor units) > 0 creates an invoice through SaleService with the given payments.
     *
     * @param  list<array{method:string, amount:int, reference?:string, tendered?:int}>  $payments
     */
    public function deliver(RepairOrder $order, int $finalCost, array $payments = []): RepairOrder
    {
        Gate::authorize('repairs.manage');
        $this->branches->authorizeOperational($order->branch_id);
        if ($order->status !== 'ready') {
            throw ValidationException::withMessages(['status' => __('Only repairs marked as ready can be delivered.')]);
        }
        if ($finalCost < 0 || ($order->warranty_claim_id && $finalCost > 0)) {
            throw ValidationException::withMessages(['final_cost' => __('Warranty repairs are delivered free of charge.')]);
        }

        return DB::transaction(function () use ($order, $finalCost, $payments) {
            $order = RepairOrder::lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'ready') {
                throw ValidationException::withMessages(['status' => __('Only repairs marked as ready can be delivered.')]);
            }
            $saleId = null;
            if ($finalCost > 0) {
                $product = Product::find((int) $this->settings->get('repair_service_product_id'));
                if (! $product || $product->product_type !== ProductType::Service || $product->track_stock || ! $product->is_active) {
                    throw ValidationException::withMessages(['final_cost' => __('Set an active, non-stock service product for repairs in Settings first.')]);
                }
                $sale = $this->sales->complete([
                    'uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp:repair:'.$order->id)->toString(),
                    'branch_id' => $order->branch_id, 'customer_id' => $order->customer_id,
                    'notes' => __('Repair :number — :item', ['number' => $order->number, 'item' => $order->item_description]),
                    'items' => [['product_id' => $product->id, 'quantity' => 1]],
                    'payments' => $payments,
                ], ['unit_prices' => [$product->id => $finalCost]]);
                $saleId = $sale->id;
            }
            $order->update(['status' => 'delivered', 'final_cost' => $finalCost, 'sale_id' => $saleId, 'delivered_at' => now()]);
            if ($order->warranty_claim_id) {
                WarrantyClaim::whereKey($order->warranty_claim_id)->where('status', 'approved')->update(['status' => 'resolved', 'updated_at' => now()]);
            }
            $this->audit->log('repair.delivered', $order, [], ['final_cost' => $finalCost, 'sale_id' => $saleId], $order->branch_id);

            return $order;
        });
    }
}
