<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a sale is committed. Integration point for Phase 3 accounting postings.
 */
class SaleCompleted
{
    use Dispatchable;

    public function __construct(public readonly Sale $sale) {}
}
