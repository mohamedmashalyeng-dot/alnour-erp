<?php

namespace App\Modules\Sales\Enums;

use App\Modules\Core\Support\HasLabels;

enum ItemCondition: string
{
    use HasLabels;

    case Good = 'good';
    case Damaged = 'damaged';
    case Defective = 'defective';

    private const LABELS = [
        'good' => 'Good / resellable',
        'damaged' => 'Damaged',
        'defective' => 'Defective',
    ];
}
