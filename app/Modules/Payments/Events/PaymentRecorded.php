<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched inside the payment's DB transaction (accounting posts in the same transaction). */
class PaymentRecorded
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
