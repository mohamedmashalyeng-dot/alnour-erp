<?php

namespace App\Modules\Optical\Services;

use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\Settings;
use App\Modules\Core\Support\Money;
use App\Modules\Customers\Models\Customer;
use App\Modules\Optical\Models\LoyaltyTransaction;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Customer loyalty points.
 *  - earn   : on a completed sale (registered customers), points per currency unit × tier multiplier
 *  - redeem : converted to an invoice discount validated on the server (balance, minimum, max % of invoice)
 *  - cancellation reverses earned points and restores redeemed points; returns reverse points pro rata
 * Every posting goes to the immutable ledger, keyed by (source, type) so replays never double-count.
 * The balance never goes below zero: if earned points were already spent, the reversal is capped and noted.
 */
class LoyaltyService
{
    public function __construct(private Settings $settings, private AuditLogger $audit) {}

    public function enabled(): bool
    {
        return $this->settings->bool('loyalty_enabled');
    }

    /** @return array{name:string, multiplier_bp:int} */
    public function tier(Customer $customer): array
    {
        $life = (int) $customer->loyalty_lifetime_points;
        if ($life >= (int) $this->settings->get('loyalty_gold_threshold', 5000)) {
            return ['name' => 'gold', 'multiplier_bp' => 15000];
        }
        if ($life >= (int) $this->settings->get('loyalty_silver_threshold', 1000)) {
            return ['name' => 'silver', 'multiplier_bp' => 12500];
        }

        return ['name' => 'bronze', 'multiplier_bp' => 10000];
    }

    /** Value of $points in minor units. */
    public function value(int $points): int
    {
        return $points * (int) $this->settings->get('loyalty_point_value', 5);
    }

    /**
     * Validate a redemption against the live balance and return the discount it is worth.
     * $invoiceAmount = the amount the discount applies to (after other discounts).
     */
    public function quote(?Customer $customer, int $points, int $invoiceAmount): int
    {
        if ($points <= 0) {
            return 0;
        }
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['redeem_points' => __('The loyalty program is not enabled.')]);
        }
        if (! $customer || $customer->is_walk_in) {
            throw ValidationException::withMessages(['redeem_points' => __('Select a registered customer to redeem points.')]);
        }
        $min = (int) $this->settings->get('loyalty_min_redeem', 100);
        if ($points < $min) {
            throw ValidationException::withMessages(['redeem_points' => __('At least :min points are needed to redeem.', ['min' => $min])]);
        }
        $balance = (int) Customer::whereKey($customer->id)->value('loyalty_points');
        if ($points > $balance) {
            throw ValidationException::withMessages(['redeem_points' => __('The customer has only :balance points.', ['balance' => $balance])]);
        }
        $value = $this->value($points);
        $maxPercent = (int) $this->settings->get('loyalty_max_redeem_percent', 50);
        if ($value * 100 > $invoiceAmount * $maxPercent) {
            throw ValidationException::withMessages(['redeem_points' => __('Points can cover at most :max% of the invoice.', ['max' => $maxPercent])]);
        }

        return $value;
    }

    /** Called inside the sale transaction after the sale is saved. */
    public function redeem(Sale $sale, Customer $customer, int $points): void
    {
        $this->post($customer, -$points, 'redeem', $sale, __('Redeemed on invoice :number', ['number' => $sale->invoice_number]), strict: true);
    }

    /** SaleCompleted listener. */
    public function earn(Sale $sale): void
    {
        if (! $this->enabled() || ! $sale->customer_id || $sale->total <= 0) {
            return;
        }
        $customer = Customer::find($sale->customer_id);
        if (! $customer || $customer->is_walk_in) {
            return;
        }
        $perUnit = (int) $this->settings->get('loyalty_points_per_unit', 1);
        $points = intdiv($sale->total * $perUnit * $this->tier($customer)['multiplier_bp'], (10 ** Money::decimals()) * 10000);
        if ($points <= 0) {
            return;
        }
        if ($this->post($customer, $points, 'earn', $sale, __('Earned on invoice :number', ['number' => $sale->invoice_number]))) {
            Sale::whereKey($sale->id)->update(['loyalty_points_earned' => $points]);
            $sale->loyalty_points_earned = $points;
        }
    }

    /** SaleCancelled listener: reverse what was earned, give back what was redeemed. */
    public function reverseSale(Sale $sale): void
    {
        $sale = Sale::find($sale->id);
        if (! $sale->customer_id) {
            return;
        }
        $customer = Customer::find($sale->customer_id);
        $remainingEarned = $sale->loyalty_points_earned - $this->reversedEarnFor($sale);
        if ($remainingEarned > 0) {
            $this->post($customer, -$remainingEarned, 'reverse_earn', $sale, __('Cancellation of :number', ['number' => $sale->invoice_number]));
        }
        if ($sale->loyalty_points_redeemed > 0) {
            $this->post($customer, $sale->loyalty_points_redeemed, 'restore', $sale, __('Cancellation of :number', ['number' => $sale->invoice_number]));
        }
    }

    /** SaleReturnCompleted listener: earned points reversed in proportion to the credit granted. */
    public function reverseForReturn(SaleReturn $return): void
    {
        $sale = Sale::find($return->sale_id);
        if (! $sale?->customer_id || $sale->loyalty_points_earned <= 0 || $sale->total <= 0 || $return->total <= 0) {
            return;
        }
        $points = min(intdiv($sale->loyalty_points_earned * $return->total, $sale->total), $sale->loyalty_points_earned - $this->reversedEarnFor($sale));
        if ($points > 0) {
            $this->post(Customer::find($sale->customer_id), -$points, 'reverse_earn', $return, __('Return :number', ['number' => $return->number]));
        }
    }

    /** Manual correction by an authorized user (audited). */
    public function adjust(Customer $customer, int $points, string $notes): LoyaltyTransaction
    {
        Gate::authorize('loyalty.adjust');
        if ($points === 0 || trim($notes) === '' || mb_strlen($notes) > 255) {
            throw ValidationException::withMessages(['points' => __('Enter a non-zero number of points and a reason.')]);
        }

        return DB::transaction(function () use ($customer, $points, $notes) {
            $tx = $this->post($customer, $points, 'adjust', null, $notes, strict: true);
            $this->audit->log('loyalty.adjusted', $customer, [], ['points' => $points, 'notes' => $notes]);

            return $tx;
        });
    }

    private function reversedEarnFor(Sale $sale): int
    {
        $returnIds = SaleReturn::where('sale_id', $sale->id)->pluck('id');

        return -(int) LoyaltyTransaction::where('type', 'reverse_earn')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('source_type', 'sale')->where('source_id', $sale->id))
                ->orWhere(fn ($w) => $w->where('source_type', 'sale_return')->whereIn('source_id', $returnIds)))
            ->sum('points');
    }

    /**
     * Post to the ledger and update the cached balance (row-locked). Returns null when this (source, type) was already posted.
     * strict = refuse to go below zero; otherwise a negative posting is capped at the current balance.
     */
    private function post(Customer $customer, int $points, string $type, $source, string $notes, bool $strict = false): ?LoyaltyTransaction
    {
        return DB::transaction(function () use ($customer, $points, $type, $source, $notes, $strict) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->first();
            if ($source && LoyaltyTransaction::where(['source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'type' => $type])->exists()) {
                return null;
            }
            $balance = (int) $locked->loyalty_points;
            if ($points < 0 && -$points > $balance) {
                if ($strict) {
                    throw ValidationException::withMessages(['redeem_points' => __('The customer has only :balance points.', ['balance' => $balance])]);
                }
                $notes .= ' — '.__('capped: :n points were already used', ['n' => -$points - $balance]);
                $points = -$balance;
            }
            $locked->loyalty_points = $balance + $points;
            if ($type === 'earn') {
                $locked->loyalty_lifetime_points += $points;
            } elseif ($type === 'reverse_earn') {
                $locked->loyalty_lifetime_points = max(0, $locked->loyalty_lifetime_points + $points);
            }
            $locked->save();
            $customer->loyalty_points = $locked->loyalty_points;

            try {
                return LoyaltyTransaction::create([
                    'customer_id' => $locked->id, 'branch_id' => $source?->branch_id, 'type' => $type, 'points' => $points,
                    'balance_after' => $locked->loyalty_points, 'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(),
                    'notes' => mb_substr($notes, 0, 255), 'user_id' => auth()->id(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['loyalty' => __('This loyalty posting was already recorded.')]);
            }
        });
    }
}
