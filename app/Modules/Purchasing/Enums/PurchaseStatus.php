<?php

namespace App\Modules\Purchasing\Enums;

use App\Modules\Core\Support\HasLabels;

enum PurchaseStatus: string
{
    use HasLabels;

    case Draft = 'draft';
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public const COLORS = ['draft' => 'slate', 'ordered' => 'sky', 'received' => 'emerald', 'cancelled' => 'rose'];

    private const LABELS = [
        'draft' => 'Draft',
        'ordered' => 'Ordered',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
    ];
}
