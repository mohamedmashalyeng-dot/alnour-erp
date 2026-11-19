<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use RuntimeException;

/**
 * Resolves posting roles ("sales.revenue", "vat.output" …) to accounts via account_mappings.
 */
class AccountResolver
{
    /** Every role the posting engine needs, with the default seeded account code. */
    public const KEYS = [
        'ar' => '1130',
        'card.clearing' => '1125',
        'inventory' => '1140',
        'vat.input' => '1150',
        'employee.advances' => '1160',
        'interbranch' => '1190',
        'ap' => '2110',
        'customer.credit' => '2120',
        'accrued.expenses' => '2130',
        'vat.output' => '2140',
        'salaries.payable' => '2150',
        'grni' => '2160',
        'owner.capital' => '3100',
        'retained.earnings' => '3200',
        'opening.equity' => '3400',
        'sales.revenue' => '4110',
        'sales.returns' => '4120',
        'other.income' => '4200',
        'cogs' => '5100',
        'inventory.shrinkage' => '5200',
        'inventory.gain' => '5300',
        'salaries.expense' => '6200',
        'bonuses.expense' => '6210',
        'cash.over_short' => '6960',
    ];

    /** @var array<string,int> */
    private array $cache = [];

    public function id(string $key): int
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $id = AccountMapping::where('key', $key)->value('account_id');
        if (! $id) {
            throw new RuntimeException("Account mapping [{$key}] is not configured.");
        }

        return $this->cache[$key] = (int) $id;
    }

    public function account(string $key): Account
    {
        return Account::findOrFail($this->id($key));
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
