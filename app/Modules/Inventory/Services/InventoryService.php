<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Events\InventoryAdjusted;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * THE ONLY place where inventory.quantity / reserved_quantity are modified.
 *
 * - Every quantity change writes an immutable InventoryTransaction in the same DB transaction.
 * - Inventory rows are locked (SELECT ... FOR UPDATE) before reading balances, so concurrent
 *   sales / transfers on the same product+branch are serialized.
 * - A document line can only post a given movement type once (unique index on reference+type).
 */
class InventoryService
{
    public function __construct(
        private BranchAccess $branches,
        private AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // User-facing operations (perform permission + branch checks)
    // ------------------------------------------------------------------

    /**
     * Opening stock may only be recorded once per product/branch, before any other movement.
     *
     * @param  list<array{product_id:int, quantity:int, unit_cost?:int|null}>  $lines
     */
    public function recordOpeningStock(int $branchId, array $lines, ?string $notes = null): array
    {
        Gate::authorize('inventory.opening-stock');
        $this->branches->authorizeOperational($branchId);
        $this->validateLines($lines);

        return DB::transaction(function () use ($branchId, $lines, $notes) {
            $results = [];
            foreach ($this->sortLines($lines) as $line) {
                $hasHistory = InventoryTransaction::where('branch_id', $branchId)
                    ->where('product_id', $line['product_id'])->exists();
                if ($hasHistory) {
                    $product = Product::find($line['product_id']);
                    throw ValidationException::withMessages(['lines' => __('Opening stock already exists or movements were recorded for :product. Use receive or adjustment instead.', ['product' => $product?->sku])]);
                }
                $results[] = $this->move($branchId, $line['product_id'], MovementType::Opening, $line['quantity'], [
                    'unit_cost' => $line['unit_cost'] ?? null,
                    'notes' => $notes,
                    'reason' => __('Opening stock'),
                ]);
                event(new InventoryAdjusted(end($results)));
            }

            return $results;
        });
    }

    /**
     * Manual stock receipt (e.g. goods received without a purchase order).
     */
    public function receiveStock(int $branchId, array $lines, string $reason, ?string $notes = null): array
    {
        Gate::authorize('inventory.receive');
        $this->branches->authorizeOperational($branchId);
        $this->validateLines($lines);
        $this->requireReason($reason);

        return DB::transaction(function () use ($branchId, $lines, $reason, $notes) {
            $results = [];
            foreach ($this->sortLines($lines) as $line) {
                $results[] = $this->move($branchId, $line['product_id'], MovementType::Receipt, $line['quantity'], [
                    'unit_cost' => $line['unit_cost'] ?? null,
                    'reason' => $reason,
                    'notes' => $notes,
                ]);
                event(new InventoryAdjusted(end($results)));
            }

            return $results;
        });
    }

    /**
     * Adjust stock by a signed quantity. A reason is mandatory.
     * Going below zero requires the inventory.allow-negative permission AND an explicit flag.
     */
    public function adjust(int $branchId, int $productId, int $signedQuantity, string $reason, ?string $notes = null, bool $allowNegative = false): InventoryTransaction
    {
        Gate::authorize('inventory.adjust');
        $this->branches->authorizeOperational($branchId);
        $this->requireReason($reason);
        if ($signedQuantity === 0) {
            throw ValidationException::withMessages(['quantity' => __('Adjustment quantity cannot be zero.')]);
        }
        if ($allowNegative) {
            Gate::authorize('inventory.allow-negative');
        }

        return DB::transaction(function () use ($branchId, $productId, $signedQuantity, $reason, $notes, $allowNegative) {
            $tx = $this->move(
                $branchId,
                $productId,
                $signedQuantity > 0 ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                abs($signedQuantity),
                ['reason' => $reason, 'notes' => $notes, 'allow_negative' => $allowNegative],
            );
            event(new InventoryAdjusted($tx));

            return $tx;
        });
    }

    public function setMinStockLevel(int $branchId, int $productId, int $level): Inventory
    {
        Gate::authorize('inventory.adjust');
        $this->branches->authorize($branchId);
        if ($level < 0) {
            throw ValidationException::withMessages(['min_stock_level' => __('Minimum stock level cannot be negative.')]);
        }

        return DB::transaction(function () use ($branchId, $productId, $level) {
            $row = $this->lockRow($branchId, $productId);
            $old = $row->min_stock_level;
            $row->min_stock_level = $level;
            $row->save();
            $this->audit->log('inventory.min_level_changed', $row, ['min_stock_level' => $old], ['min_stock_level' => $level], $branchId);

            return $row;
        });
    }

    // ------------------------------------------------------------------
    // Engine (used by other modules: transfers, purchases, sales, returns)
    // Callers are responsible for authorization.
    // ------------------------------------------------------------------

    /**
     * Apply one stock movement.
     *
     * @param  array{reason?:string|null, notes?:string|null, reference?:Model|null, unit_cost?:int|null,
     *               allow_negative?:bool, consume_reserved?:int, user_id?:int|null}  $options
     */
    public function move(int $branchId, int $productId, MovementType $type, int $quantity, array $options = []): InventoryTransaction
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Movement quantity must be a positive integer.');
        }

        return DB::transaction(function () use ($branchId, $productId, $type, $quantity, $options) {
            $product = Product::query()->findOrFail($productId);
            $row = $this->lockRow($branchId, $productId);

            $before = $row->quantity;
            $consumeReserved = (int) ($options['consume_reserved'] ?? 0);

            if ($type->isIncrease()) {
                $after = $before + $quantity;
            } else {
                // Stock reserved for other documents (approved transfers) is not sellable.
                $available = $before - ($row->reserved_quantity - $consumeReserved);
                $after = $before - $quantity;
                $allowNegative = (bool) ($options['allow_negative'] ?? false);
                if ($quantity > $available && ! $allowNegative) {
                    throw InsufficientStockException::for($product->sku.' — '.$product->name, max(0, $available), $quantity);
                }
            }

            $row->quantity = $after;
            if ($consumeReserved > 0) {
                $row->reserved_quantity = max(0, $row->reserved_quantity - $consumeReserved);
            }
            $row->save();

            $reference = $options['reference'] ?? null;

            return InventoryTransaction::create([
                'branch_id' => $branchId,
                'product_id' => $productId,
                'user_id' => array_key_exists('user_id', $options) ? $options['user_id'] : auth()->id(),
                'type' => $type,
                'quantity' => $type->isIncrease() ? $quantity : -$quantity,
                'balance_before' => $before,
                'balance_after' => $after,
                'unit_cost' => $options['unit_cost'] ?? $product->cost_price,
                'reason' => $options['reason'] ?? null,
                'notes' => $options['notes'] ?? null,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    /**
     * Reserve stock (e.g. approved transfer). Does not change on-hand quantity.
     */
    public function reserve(int $branchId, int $productId, int $quantity, bool $allowNegative = false): void
    {
        DB::transaction(function () use ($branchId, $productId, $quantity, $allowNegative) {
            $row = $this->lockRow($branchId, $productId);
            if (! $allowNegative && $row->available() < $quantity) {
                $product = Product::find($productId);
                throw InsufficientStockException::for($product->sku.' — '.$product->name, max(0, $row->available()), $quantity);
            }
            $row->reserved_quantity += $quantity;
            $row->save();
        });
    }

    public function release(int $branchId, int $productId, int $quantity): void
    {
        DB::transaction(function () use ($branchId, $productId, $quantity) {
            $row = $this->lockRow($branchId, $productId);
            $row->reserved_quantity = max(0, $row->reserved_quantity - $quantity);
            $row->save();
        });
    }

    public function available(int $branchId, int $productId): int
    {
        $row = Inventory::where('branch_id', $branchId)->where('product_id', $productId)->first();

        return $row ? $row->available() : 0;
    }

    /**
     * Fetch (creating if necessary) and lock the inventory row.
     */
    public function lockRow(int $branchId, int $productId): Inventory
    {
        Inventory::query()->insertOrIgnore([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'min_stock_level' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Inventory::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock rows in a deterministic order (by product id) to avoid deadlocks between concurrent documents.
     *
     * @param  list<array{product_id:int}>  $lines
     */
    public function sortLines(array $lines): array
    {
        usort($lines, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);

        return $lines;
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
            if (isset($line['unit_cost']) && (! is_int($line['unit_cost']) || $line['unit_cost'] < 0)) {
                throw ValidationException::withMessages(["lines.$i.unit_cost" => __('Invalid unit cost.')]);
            }
        }
    }

    private function requireReason(?string $reason): void
    {
        if (trim((string) $reason) === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => __('A reason is required (max 255 characters).')]);
        }
    }
}
