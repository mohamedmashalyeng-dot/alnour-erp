<?php

namespace App\Modules\Website\Enums;

use App\Modules\Core\Support\HasLabels;

enum InquiryType: string
{
    use HasLabels;

    case Product = 'product';
    case Exam = 'exam';
    case Contact = 'contact';

    private const LABELS = [
        'product' => 'Product order',
        'exam' => 'Eye exam booking',
        'contact' => 'General inquiry',
    ];

    public const COLORS = ['product' => 'indigo', 'exam' => 'sky', 'contact' => 'slate'];
}
