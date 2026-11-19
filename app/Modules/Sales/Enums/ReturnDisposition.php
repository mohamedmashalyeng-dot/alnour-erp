<?php

namespace App\Modules\Sales\Enums;

use App\Modules\Core\Support\HasLabels;

enum ReturnDisposition: string
{
    use HasLabels;

    case Restock = 'restock';
    case WriteOff = 'write_off';
    case SupplierReturn = 'supplier_return';

    private const LABELS = [
        'restock' => 'Return to stock',
        'write_off' => 'Write off',
        'supplier_return' => 'Return to supplier',
    ];
}
