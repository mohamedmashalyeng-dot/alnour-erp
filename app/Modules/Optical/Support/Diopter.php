<?php

namespace App\Modules\Optical\Support;

/**
 * Optical values as integers (no floats): dioptres in hundredths (−2.25 → −225), PD/BC/DIA in tenths or hundredths of mm.
 * Accepts Arabic-Indic digits and both "." and "٫" as decimal separators.
 */
final class Diopter
{
    /** Parse "+1.25", "-0.5", "١٫٧٥" → hundredths; null on empty; false on invalid. */
    public static function parse(string|int|null $value, int $decimals = 2): int|false|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $v = strtr(trim((string) $value), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', ',' => '.', '−' => '-']);
        if (! preg_match('/^([+-]?)(\d{1,3})(?:\.(\d{1,'.$decimals.'}))?$/', $v, $m)) {
            return false;
        }
        $n = (int) $m[2] * 10 ** $decimals + (int) str_pad($m[3] ?? '', $decimals, '0');

        return $m[1] === '-' ? -$n : $n;
    }

    /** −225 → "-2.25", 0 → "0.00", 125 → "+1.25". */
    public static function format(?int $hundredths, bool $signed = true): string
    {
        if ($hundredths === null) {
            return '';
        }
        $abs = abs($hundredths);
        $s = intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($hundredths < 0 ? '-' : ($signed && $hundredths > 0 ? '+' : '')).$s;
    }

    /** 635 → "63.5" */
    public static function formatTenths(?int $tenths): string
    {
        return $tenths === null ? '' : intdiv($tenths, 10).'.'.($tenths % 10);
    }

    public static function isQuarterStep(int $hundredths): bool
    {
        return $hundredths % 25 === 0;
    }
}
