<?php

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched inside the receiving transaction. */
class PurchaseReceived
{
    use Dispatchable;

    public function __construct(public readonly PurchaseOrder $purchaseOrder) {}
}
