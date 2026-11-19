<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SaleReturn;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a customer return is inspected and completed. Integration point for Phase 3.
 */
class SaleReturnCompleted
{
    use Dispatchable;

    public function __construct(public readonly SaleReturn $saleReturn) {}
}
