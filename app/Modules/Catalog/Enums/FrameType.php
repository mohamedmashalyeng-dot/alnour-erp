<?php

namespace App\Modules\Catalog\Enums;

use App\Modules\Core\Support\HasLabels;

enum FrameType: string
{
    use HasLabels;

    case FullRim = 'full_rim';
    case SemiRimless = 'semi_rimless';
    case Rimless = 'rimless';

    private const LABELS = [
        'full_rim' => 'Full rim',
        'semi_rimless' => 'Semi-rimless',
        'rimless' => 'Rimless',
    ];
}
