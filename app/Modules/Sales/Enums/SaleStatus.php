<?php

namespace App\Modules\Sales\Enums;

use App\Modules\Core\Support\HasLabels;

enum SaleStatus: string
{
    use HasLabels;

    case Draft = 'draft';
    case Completed = 'completed';
    case PartiallyPaid = 'partially_paid';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public const COLORS = ['draft' => 'slate', 'completed' => 'emerald', 'partially_paid' => 'amber', 'refunded' => 'indigo', 'cancelled' => 'rose'];

    private const LABELS = [
        'draft' => 'Draft',
        'completed' => 'Completed',
        'partially_paid' => 'Partially paid',
        'refunded' => 'Refunded',
        'cancelled' => 'Cancelled',
    ];
}
