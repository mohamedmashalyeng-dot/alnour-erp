<?php

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\InventoryTransaction;
use Illuminate\Foundation\Events\Dispatchable;

/** Manual stock movement (opening, receipt without PO, adjustment). Dispatched inside its transaction. */
class InventoryAdjusted
{
    use Dispatchable;

    public function __construct(public readonly InventoryTransaction $transaction) {}
}
