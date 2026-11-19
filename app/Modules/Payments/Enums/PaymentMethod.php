<?php

namespace App\Modules\Payments\Enums;

use App\Modules\Core\Support\HasLabels;

enum PaymentMethod: string
{
    use HasLabels;

    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case StoreCredit = 'store_credit';

    private const LABELS = [
        'cash' => 'Cash',
        'card' => 'Card',
        'bank_transfer' => 'Bank transfer',
        'cheque' => 'Cheque',
        'store_credit' => 'Store credit',
    ];
}
