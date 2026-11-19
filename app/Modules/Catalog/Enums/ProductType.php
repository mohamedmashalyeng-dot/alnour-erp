<?php

namespace App\Modules\Catalog\Enums;

use App\Modules\Core\Support\HasLabels;

enum ProductType: string
{
    use HasLabels;

    case Frame = 'frame';
    case Sunglasses = 'sunglasses';
    case OphthalmicLens = 'ophthalmic_lens';
    case ContactLens = 'contact_lens';
    case Solution = 'solution';
    case Accessory = 'accessory';
    case Service = 'service';

    private const LABELS = [
        'frame' => 'Optical frame',
        'sunglasses' => 'Sunglasses',
        'ophthalmic_lens' => 'Ophthalmic lens',
        'contact_lens' => 'Contact lens',
        'solution' => 'Lens solution',
        'accessory' => 'Accessory',
        'service' => 'Service',
    ];

    /** Spec keys allowed in products.specifications per type (prescription-related data). */
    public function specKeys(): array
    {
        return match ($this) {
            self::OphthalmicLens => ['lens_index', 'coating', 'sph_min', 'sph_max', 'cyl_min', 'cyl_max', 'add_max'],
            self::ContactLens => ['base_curve', 'diameter', 'power', 'cylinder', 'axis', 'replacement', 'pack_size'],
            self::Frame, self::Sunglasses => ['lens_width', 'bridge_width', 'temple_length', 'uv_protection', 'polarized'],
            default => [],
        };
    }
}
