<?php

namespace App\Modules\Website\Enums;

use App\Modules\Core\Support\HasLabels;

enum InquiryStatus: string
{
    use HasLabels;

    case New = 'new';
    case Contacted = 'contacted';
    case Done = 'done';
    case Spam = 'spam';

    private const LABELS = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'done' => 'Completed',
        'spam' => 'Spam',
    ];

    public const COLORS = ['new' => 'amber', 'contacted' => 'sky', 'done' => 'emerald', 'spam' => 'slate'];
}
