<?php

namespace App\Modules\Inventory\Enums;

use App\Modules\Core\Support\HasLabels;

enum MovementType: string
{
    use HasLabels;

    case Opening = 'opening';
    case Receipt = 'receipt';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case PurchaseReceipt = 'purchase_receipt';
    case Sale = 'sale';
    case SaleCancel = 'sale_cancel';
    case SaleReturn = 'sale_return';

    private const LABELS = [
        'opening' => 'Opening stock',
        'receipt' => 'Stock receipt',
        'adjustment_in' => 'Adjustment (increase)',
        'adjustment_out' => 'Adjustment (decrease)',
        'transfer_out' => 'Transfer out',
        'transfer_in' => 'Transfer in',
        'purchase_receipt' => 'Purchase receipt',
        'sale' => 'Sale',
        'sale_cancel' => 'Sale cancellation',
        'sale_return' => 'Customer return',
    ];

    public function isIncrease(): bool
    {
        return in_array($this, [self::Opening, self::Receipt, self::AdjustmentIn, self::TransferIn, self::PurchaseReceipt, self::SaleCancel, self::SaleReturn], true);
    }
}
