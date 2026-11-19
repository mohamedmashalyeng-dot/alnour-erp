<?php

namespace App\Modules\Catalog\Enums;

use App\Modules\Core\Support\HasLabels;

enum Gender: string
{
    use HasLabels;

    case Men = 'men';
    case Women = 'women';
    case Unisex = 'unisex';
    case Kids = 'kids';

    private const LABELS = [
        'men' => 'Men',
        'women' => 'Women',
        'unisex' => 'Unisex',
        'kids' => 'Kids',
    ];
}
