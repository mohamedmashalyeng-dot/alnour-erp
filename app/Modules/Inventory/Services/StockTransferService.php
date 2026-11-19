<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Events\TransferReceived;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Inter-branch transfer workflow:
 *
 *   pending --approve--> approved (stock reserved at source)
 *   approved --dispatch--> dispatched (stock leaves source: transfer_out)
 *   dispatched --receive--> received (stock enters destination: transfer_in)
 *   pending|approved --cancel--> cancelled (reservation released)
 *
 * Each transition locks the transfer row and checks the current status, so a transfer
 * can never be dispatched or received twice.
 */
class StockTransferService
{
    public function __construct(
        private InventoryService $inventory,
        private BranchAccess $branches,
        private DocumentNumberGenerator $numbers,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{product_id:int, quantity:int}>  $lines
     */
    public function create(int $fromBranchId, int $toBranchId, array $lines, ?string $notes = null): StockTransfer
    {
        Gate::authorize('transfers.create');
        $from = $this->branches->authorizeOperational($fromBranchId);
        $to = Branch::query()->findOrFail($toBranchId);
        if (! $to->is_active) {
            throw ValidationException::withMessages(['to_branch_id' => __('This branch is inactive.')]);
        }
        if ($fromBranchId === $toBranchId) {
            throw ValidationException::withMessages(['to_branch_id' => __('Source and destination branches must be different.')]);
        }
        $this->validateLines($lines);

        return DB::transaction(function () use ($from, $toBranchId, $lines, $notes) {
            $transfer = StockTransfer::create([
                'number' => $this->numbers->next('transfer', null),
                'from_branch_id' => $from->id,
                'to_branch_id' => $toBranchId,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);
            $transfer->status = TransferStatus::Pending;
            $transfer->save();

            foreach ($this->inventory->sortLines($lines) as $line) {
                $transfer->items()->create(['product_id' => $line['product_id'], 'quantity' => $line['quantity']]);
            }
            $this->audit->log('transfer.created', $transfer, [], ['lines' => $lines], $from->id);

            return $transfer->load('items');
        });
    }

    public function approve(StockTransfer $transfer): StockTransfer
    {
        Gate::authorize('transfers.approve');
        $this->branches->authorize($transfer->from_branch_id);

        return $this->transition($transfer, TransferStatus::Pending, TransferStatus::Approved, function (StockTransfer $t) {
            foreach ($t->items->sortBy('product_id') as $item) {
                $this->inventory->reserve($t->from_branch_id, $item->product_id, $item->quantity);
            }
            $t->approved_by = auth()->id();
            $t->approved_at = now();
        });
    }

    public function dispatch(StockTransfer $transfer): StockTransfer
    {
        Gate::authorize('transfers.dispatch');
        $this->branches->authorizeOperational($transfer->from_branch_id);

        return $this->transition($transfer, TransferStatus::Approved, TransferStatus::Dispatched, function (StockTransfer $t) {
            foreach ($t->items->sortBy('product_id') as $item) {
                $this->inventory->move($t->from_branch_id, $item->product_id, MovementType::TransferOut, $item->quantity, [
                    'reference' => $item,
                    'consume_reserved' => $item->quantity,
                    'reason' => __('Transfer :number', ['number' => $t->number]),
                ]);
            }
            $t->dispatched_by = auth()->id();
            $t->dispatched_at = now();
        });
    }

    public function receive(StockTransfer $transfer): StockTransfer
    {
        Gate::authorize('transfers.receive');
        $this->branches->authorizeOperational($transfer->to_branch_id);

        return $this->transition($transfer, TransferStatus::Dispatched, TransferStatus::Received, function (StockTransfer $t) {
            foreach ($t->items->sortBy('product_id') as $item) {
                // Carry the cost recorded when the goods left the source branch.
                $outCost = DB::table('inventory_transactions')
                    ->where('reference_type', $item->getMorphClass())
                    ->where('reference_id', $item->id)
                    ->where('type', MovementType::TransferOut->value)
                    ->value('unit_cost');

                $this->inventory->move($t->to_branch_id, $item->product_id, MovementType::TransferIn, $item->quantity, [
                    'reference' => $item,
                    'unit_cost' => $outCost,
                    'reason' => __('Transfer :number', ['number' => $t->number]),
                ]);
            }
            $t->received_by = auth()->id();
            $t->received_at = now();
            event(new TransferReceived($t));
        });
    }

    public function cancel(StockTransfer $transfer, string $reason): StockTransfer
    {
        Gate::authorize('transfers.cancel');
        $this->branches->authorize($transfer->from_branch_id);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['cancel_reason' => __('A reason is required (max 255 characters).')]);
        }

        return DB::transaction(function () use ($transfer, $reason) {
            /** @var StockTransfer $t */
            $t = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if (! in_array($t->status, [TransferStatus::Pending, TransferStatus::Approved], true)) {
                throw ValidationException::withMessages(['status' => __('Only pending or approved transfers can be cancelled. Dispatched goods must be received.')]);
            }
            if ($t->status === TransferStatus::Approved) {
                foreach ($t->items->sortBy('product_id') as $item) {
                    $this->inventory->release($t->from_branch_id, $item->product_id, $item->quantity);
                }
            }
            $old = $t->status->value;
            $t->status = TransferStatus::Cancelled;
            $t->cancelled_by = auth()->id();
            $t->cancelled_at = now();
            $t->cancel_reason = mb_substr($reason, 0, 255);
            $t->save();
            $this->audit->log('transfer.cancelled', $t, ['status' => $old], ['status' => 'cancelled', 'reason' => $reason], $t->from_branch_id);

            return $t;
        });
    }

    private function transition(StockTransfer $transfer, TransferStatus $from, TransferStatus $to, \Closure $apply): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $from, $to, $apply) {
            /** @var StockTransfer $t */
            $t = StockTransfer::query()->lockForUpdate()->with('items')->findOrFail($transfer->id);
            if ($t->status !== $from) {
                throw ValidationException::withMessages(['status' => __('This transfer is :status and cannot move to :target.', [
                    'status' => $t->status->label(), 'target' => $to->label(),
                ])]);
            }
            $apply($t);
            $t->status = $to;
            $t->save();
            $this->audit->log('transfer.'.$to->value, $t, ['status' => $from->value], ['status' => $to->value], $t->from_branch_id);

            return $t;
        });
    }

    private function validateLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Add at least one product.')]);
        }
        $seen = [];
        foreach ($lines as $i => $line) {
            if (! isset($line['product_id'], $line['quantity']) || ! is_int($line['quantity']) || $line['quantity'] <= 0) {
                throw ValidationException::withMessages(["lines.$i.quantity" => __('Quantity must be a positive whole number.')]);
            }
            if (isset($seen[$line['product_id']])) {
                throw ValidationException::withMessages(["lines.$i.product_id" => __('The same product appears more than once.')]);
            }
            $seen[$line['product_id']] = true;
            $product = Product::find($line['product_id']);
            if (! $product || ! $product->track_stock) {
                throw ValidationException::withMessages(["lines.$i.product_id" => __('Invalid product.')]);
            }
        }
    }
}
