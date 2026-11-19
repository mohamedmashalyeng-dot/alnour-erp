<?php

namespace App\Modules\Inventory\Enums;

use App\Modules\Core\Support\HasLabels;

enum TransferStatus: string
{
    use HasLabels;

    case Pending = 'pending';
    case Approved = 'approved';
    case Dispatched = 'dispatched';
    case Received = 'received';
    case Cancelled = 'cancelled';

    private const LABELS = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'dispatched' => 'Dispatched',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
    ];

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'sky',
            self::Dispatched => 'indigo',
            self::Received => 'emerald',
            self::Cancelled => 'rose',
        };
    }
}
