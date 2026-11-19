<?php

namespace App\Modules\Accounting\Enums;

use App\Modules\Core\Support\HasLabels;

enum AccountType: string
{
    use HasLabels;

    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Cogs = 'cogs';
    case Expense = 'expense';

    private const LABELS = [
        'asset' => 'Assets',
        'liability' => 'Liabilities',
        'equity' => 'Equity',
        'revenue' => 'Revenue',
        'cogs' => 'Cost of goods sold',
        'expense' => 'Expenses',
    ];

    public function normalBalance(): string
    {
        return in_array($this, [self::Asset, self::Cogs, self::Expense], true) ? 'debit' : 'credit';
    }

    /** Income-statement accounts are closed into retained earnings at year end. */
    public function isProfitAndLoss(): bool
    {
        return in_array($this, [self::Revenue, self::Cogs, self::Expense], true);
    }
}
