<?php

namespace App\Modules\Sales\Enums;

use App\Modules\Core\Support\HasLabels;

enum SaleReturnStatus: string
{
    use HasLabels;

    case Pending = 'pending';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public const COLORS = ['pending' => 'amber', 'completed' => 'emerald', 'rejected' => 'rose'];

    private const LABELS = [
        'pending' => 'Pending inspection',
        'completed' => 'Completed',
        'rejected' => 'Rejected',
    ];
}
