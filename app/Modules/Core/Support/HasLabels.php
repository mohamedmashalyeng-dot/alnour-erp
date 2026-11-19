<?php

namespace App\Modules\Core\Support;

/**
 * For string-backed enums: translatable labels and select options.
 */
trait HasLabels
{
    public function label(): string
    {
        return __(self::LABELS[$this->value] ?? $this->value);
    }

    /** Badge color; enums may define `public const COLORS = [value => color]`. */
    public function color(): string
    {
        return defined(self::class.'::COLORS') ? (constant(self::class.'::COLORS')[$this->value] ?? 'slate') : 'slate';
    }

    /** @return array<string,string> value => label */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
