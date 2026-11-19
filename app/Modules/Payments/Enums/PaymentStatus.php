<?php

namespace App\Modules\Payments\Enums;

use App\Modules\Core\Support\HasLabels;

enum PaymentStatus: string
{
    use HasLabels;

    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public const COLORS = ['unpaid' => 'rose', 'partial' => 'amber', 'paid' => 'emerald'];

    private const LABELS = [
        'unpaid' => 'Unpaid',
        'partial' => 'Partially paid',
        'paid' => 'Paid',
    ];
}
