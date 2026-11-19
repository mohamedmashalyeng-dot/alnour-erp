<?php

use App\Modules\Core\Support\Money;

if (! function_exists('money')) {
    /** Format integer minor units for display. */
    function money(?int $minor, bool $withSymbol = true): string
    {
        return Money::format((int) $minor, $withSymbol);
    }
}

if (! function_exists('money_input')) {
    /** Integer minor units -> decimal string for form inputs. */
    function money_input(?int $minor): string
    {
        return Money::toDecimal((int) $minor);
    }
}
