<?php

namespace App\Modules\Core\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\TaxRate;
use App\Modules\Core\Support\Money;

/**
 * Line and document totals in integer minor units.
 *
 * For each line:  gross = qty * unit_price - discount
 *   tax-inclusive prices: tax = gross * r / (1 + r), line_total = gross
 *   tax-exclusive prices: tax = gross * r,           line_total = gross + tax
 *
 * A document-level discount is spread across lines proportionally (largest remainder),
 * so line totals always add up exactly to the document total.
 */
class TaxCalculator
{
    public function __construct(private Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->bool('tax_enabled');
    }

    public function pricesIncludeTax(): bool
    {
        return $this->settings->bool('prices_include_tax');
    }

    public function rateFor(?Product $product): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        if ($product?->tax_rate_id) {
            $rate = TaxRate::find($product->tax_rate_id);
            if ($rate?->is_active) {
                return $rate->rate_bp;
            }
        }

        return TaxRate::defaultRate()?->rate_bp ?? 0;
    }

    /**
     * @param  list<array{quantity:int, unit_price:int, discount:int, tax_rate_bp:int}>  $lines
     * @return array{lines: list<array{quantity:int, unit_price:int, discount:int, tax_rate_bp:int, tax_amount:int, line_total:int}>,
     *               subtotal:int, discount_total:int, tax_total:int, total:int}
     */
    public function calculate(array $lines, int $documentDiscount, bool $inclusive): array
    {
        $grossBefore = [];
        foreach ($lines as $i => $line) {
            if ($line['quantity'] <= 0 || $line['unit_price'] < 0 || $line['discount'] < 0) {
                throw new \InvalidArgumentException('Invalid line values.');
            }
            $grossBefore[$i] = max(0, $line['quantity'] * $line['unit_price'] - $line['discount']);
        }
        $sumGross = array_sum($grossBefore);
        if ($documentDiscount < 0 || $documentDiscount > $sumGross) {
            throw new \InvalidArgumentException('Discount exceeds the amount.');
        }
        $extra = Money::allocate($documentDiscount, $grossBefore);

        $out = ['lines' => [], 'subtotal' => 0, 'discount_total' => 0, 'tax_total' => 0, 'total' => 0];
        foreach ($lines as $i => $line) {
            $lineSubtotal = $line['quantity'] * $line['unit_price'];
            $discount = min($lineSubtotal, $line['discount'] + ($extra[$i] ?? 0));
            $gross = $lineSubtotal - $discount;
            $rate = $line['tax_rate_bp'];
            if ($inclusive) {
                $tax = Money::inclusiveTax($gross, $rate);
                $total = $gross;
            } else {
                $tax = Money::percentOf($gross, $rate);
                $total = $gross + $tax;
            }
            $out['lines'][$i] = $line + ['discount' => $discount, 'tax_amount' => $tax, 'line_total' => $total];
            $out['lines'][$i]['discount'] = $discount;
            $out['subtotal'] += $lineSubtotal;
            $out['discount_total'] += $discount;
            $out['tax_total'] += $tax;
            $out['total'] += $total;
        }

        return $out;
    }
}
