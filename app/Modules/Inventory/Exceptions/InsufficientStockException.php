<?php

namespace App\Modules\Inventory\Exceptions;

use Illuminate\Validation\ValidationException;

class InsufficientStockException extends ValidationException
{
    public static function for(string $productLabel, int $available, int $requested, string $field = 'quantity'): self
    {
        return static::withMessages([
            $field => __('Insufficient stock for :product: available :available, requested :requested.', [
                'product' => $productLabel,
                'available' => $available,
                'requested' => $requested,
            ]),
        ]);
    }
}
