<?php

namespace App\Modules\Catalog\Enums;

use App\Modules\Core\Support\HasLabels;

enum LensType: string
{
    use HasLabels;

    case None = 'none';
    case Plano = 'plano';
    case SingleVision = 'single_vision';
    case Bifocal = 'bifocal';
    case Progressive = 'progressive';
    case Photochromic = 'photochromic';
    case Polarized = 'polarized';
    case BlueLight = 'blue_light';

    private const LABELS = [
        'none' => 'No lens',
        'plano' => 'Plano / demo lens',
        'single_vision' => 'Single vision',
        'bifocal' => 'Bifocal',
        'progressive' => 'Progressive',
        'photochromic' => 'Photochromic',
        'polarized' => 'Polarized',
        'blue_light' => 'Blue light filter',
    ];
}
