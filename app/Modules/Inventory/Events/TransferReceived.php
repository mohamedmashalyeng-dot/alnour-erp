<?php

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched inside the receiving transaction. */
class TransferReceived
{
    use Dispatchable;

    public function __construct(public readonly StockTransfer $transfer) {}
}
