<?php

namespace App\Modules\Catalog\Enums;

use App\Modules\Core\Support\HasLabels;

enum FrameMaterial: string
{
    use HasLabels;

    case Metal = 'metal';
    case Acetate = 'acetate';
    case Titanium = 'titanium';
    case Plastic = 'plastic';
    case Tr90 = 'tr90';
    case StainlessSteel = 'stainless_steel';
    case Mixed = 'mixed';

    private const LABELS = [
        'metal' => 'Metal',
        'acetate' => 'Acetate',
        'titanium' => 'Titanium',
        'plastic' => 'Plastic',
        'tr90' => 'TR90',
        'stainless_steel' => 'Stainless steel',
        'mixed' => 'Mixed',
    ];
}
