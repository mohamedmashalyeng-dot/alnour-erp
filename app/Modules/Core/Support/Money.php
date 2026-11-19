<?php

namespace App\Modules\Core\Support;

use InvalidArgumentException;

/**
 * Money helpers. Amounts are always integers in minor units (e.g. halalas).
 * Parsing is done on strings so no floating-point arithmetic is ever involved.
 */
final class Money
{
    public static function decimals(): int
    {
        return (int) config('erp.currency.decimals', 2);
    }

    /**
     * Parse a user-entered decimal string ("1,250.5") into minor units (125050).
     */
    public static function parse(string|int|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * (10 ** self::decimals());
        }

        $value = trim(str_replace([',', ' ', '٬'], '', $value));
        // Normalise Arabic-Indic digits and decimal separator.
        $value = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid money amount [{$value}].");
        }

        $decimals = self::decimals();
        $fraction = $m[3] ?? '';
        if (strlen($fraction) > $decimals) {
            throw new InvalidArgumentException("Too many decimal places in [{$value}].");
        }
        $fraction = str_pad($fraction, $decimals, '0');
        $minor = (int) ($m[2].$fraction);

        return $m[1] === '-' ? -$minor : $minor;
    }

    public static function isValid(?string $value): bool
    {
        try {
            self::parse($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Minor units -> plain decimal string ("1250.50") suitable for inputs.
     */
    public static function toDecimal(int $minor): string
    {
        $decimals = self::decimals();
        $negative = $minor < 0;
        $abs = (string) abs($minor);
        if ($decimals === 0) {
            return ($negative ? '-' : '').$abs;
        }
        $abs = str_pad($abs, $decimals + 1, '0', STR_PAD_LEFT);
        $result = substr($abs, 0, -$decimals).'.'.substr($abs, -$decimals);

        return ($negative ? '-' : '').$result;
    }

    /**
     * Minor units -> formatted display string ("1,250.50 ر.س").
     */
    public static function format(int $minor, bool $withSymbol = true): string
    {
        $decimal = self::toDecimal($minor);
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-');
        [$int, $frac] = array_pad(explode('.', $decimal, 2), 2, null);
        $int = strrev(implode(',', str_split(strrev($int), 3)));
        $out = ($negative ? '-' : '').$int.($frac !== null ? '.'.$frac : '');

        return $withSymbol ? $out.' '.config('erp.currency.symbol') : $out;
    }

    /**
     * Percentage of an amount in basis points, rounded half-up. 1500 bp = 15%.
     */
    public static function percentOf(int $amount, int $basisPoints): int
    {
        return self::divRound($amount * $basisPoints, 10000);
    }

    /**
     * Tax contained in a tax-inclusive amount: amount * rate / (10000 + rate).
     */
    public static function inclusiveTax(int $grossAmount, int $basisPoints): int
    {
        if ($basisPoints === 0) {
            return 0;
        }

        return self::divRound($grossAmount * $basisPoints, 10000 + $basisPoints);
    }

    /**
     * Integer division rounded half away from zero.
     */
    public static function divRound(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }
        $sign = (($numerator < 0) xor ($denominator < 0)) ? -1 : 1;
        $n = abs($numerator);
        $d = abs($denominator);

        return $sign * intdiv($n * 2 + $d, $d * 2);
    }

    /**
     * Split $total across $weights proportionally, guaranteeing the parts sum to $total
     * (largest-remainder method).
     *
     * @param  array<int|string,int>  $weights
     * @return array<int|string,int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum === 0 || $total === 0) {
            return array_map(fn () => 0, $weights);
        }
        $result = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $key => $w) {
            $share = intdiv($total * $w, $sum);
            $result[$key] = $share;
            $remainders[$key] = ($total * $w) % $sum;
            $allocated += $share;
        }
        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if ($allocated >= $total) {
                break;
            }
            $result[$key]++;
            $allocated++;
        }

        return $result;
    }
}
