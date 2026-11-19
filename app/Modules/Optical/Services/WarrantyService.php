<?php

namespace App\Modules\Optical\Services;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\Optical\Models\Warranty;
use App\Modules\Optical\Models\WarrantyClaim;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Warranties are created automatically for sold items whose product has warranty_months > 0,
 * voided when the sale is cancelled, and reduced/voided by returns. Claims are checked against the period.
 * Replacements and refunds themselves go through the normal returns/exchange process (stock and money rules).
 */
class WarrantyService
{
    public function __construct(private BranchAccess $branches, private DocumentNumberGenerator $numbers, private AuditLogger $audit) {}

    /** SaleCompleted listener (inside the sale transaction). Idempotent: one warranty per sale item. */
    public function createForSale(Sale $sale): void
    {
        $sale->loadMissing('items.product');
        $start = CarbonImmutable::parse($sale->sold_at ?? now());
        foreach ($sale->items as $item) {
            $months = (int) ($item->product?->warranty_months ?? 0);
            if ($months <= 0 || Warranty::where('sale_item_id', $item->id)->exists()) {
                continue;
            }
            Warranty::create([
                'sale_item_id' => $item->id, 'sale_id' => $sale->id, 'product_id' => $item->product_id, 'customer_id' => $sale->customer_id,
                'branch_id' => $sale->branch_id, 'quantity' => $item->quantity, 'starts_on' => $start->toDateString(),
                'ends_on' => $start->addMonthsNoOverflow($months)->subDay()->toDateString(), 'status' => 'active',
            ]);
        }
    }

    /** SaleCancelled listener. */
    public function voidForSale(Sale $sale): void
    {
        Warranty::where('sale_id', $sale->id)->where('status', 'active')
            ->update(['status' => 'void', 'void_reason' => __('Invoice cancelled'), 'updated_at' => now()]);
    }

    /** SaleReturnCompleted listener: returned units leave the warranty; a fully returned line voids it. */
    public function applyReturn(SaleReturn $return): void
    {
        foreach (SaleReturnItem::where('sale_return_id', $return->id)->get() as $ri) {
            $warranty = Warranty::where('sale_item_id', $ri->sale_item_id)->where('status', 'active')->lockForUpdate()->first();
            if (! $warranty) {
                continue;
            }
            $left = $warranty->quantity - $ri->quantity;
            $warranty->update($left > 0 ? ['quantity' => $left] : ['status' => 'void', 'void_reason' => __('Returned (:number)', ['number' => $return->number])]);
        }
    }

    public function openClaim(Warranty $warranty, string $issue): WarrantyClaim
    {
        Gate::authorize('warranty.claims');
        $branch = $this->branches->authorizeOperational($warranty->branch_id);
        if (trim($issue) === '' || mb_strlen($issue) > 2000) {
            throw ValidationException::withMessages(['issue' => __('Describe the issue (max 2000 characters).')]);
        }
        if (! $warranty->isValidOn()) {
            throw ValidationException::withMessages(['issue' => $warranty->status !== 'active'
                ? __('This warranty is void.') : __('This warranty expired on :date.', ['date' => $warranty->ends_on->toDateString()])]);
        }
        if ($warranty->claims()->whereIn('status', ['open', 'approved'])->exists()) {
            throw ValidationException::withMessages(['issue' => __('This item already has an open claim.')]);
        }

        return DB::transaction(function () use ($warranty, $branch, $issue) {
            $claim = WarrantyClaim::create(['number' => $this->numbers->next('warranty_claim', $branch->id, $branch->code), 'warranty_id' => $warranty->id,
                'branch_id' => $branch->id, 'issue' => $issue, 'status' => 'open', 'created_by' => auth()->id()]);
            $this->audit->log('warranty.claim_opened', $claim, [], ['warranty' => $warranty->id], $branch->id);

            return $claim;
        });
    }

    public function decide(WarrantyClaim $claim, bool $approve, ?string $resolution, ?string $notes): WarrantyClaim
    {
        Gate::authorize('warranty.decide');
        $this->branches->authorize($claim->branch_id);
        Validator::make(['resolution' => $resolution, 'notes' => $notes], [
            'resolution' => [$approve ? 'required' : 'nullable', Rule::in(['repair', 'replace', 'refund'])],
            'notes' => [$approve ? 'nullable' : 'required', 'nullable', 'string', 'max:2000'],
        ])->validate();
        if ($claim->status !== 'open') {
            throw ValidationException::withMessages(['status' => __('Only open claims can be decided.')]);
        }
        if ($claim->created_by === auth()->id() && ! auth()->user()->isSuperAdmin()) {
            throw ValidationException::withMessages(['status' => __('A claim must be decided by someone other than the person who opened it.')]);
        }
        $claim->update(['status' => $approve ? 'approved' : 'rejected', 'resolution' => $approve ? $resolution : 'none',
            'resolution_notes' => $notes, 'decided_by' => auth()->id(), 'decided_at' => now()]);
        $this->audit->log($approve ? 'warranty.claim_approved' : 'warranty.claim_rejected', $claim, [], ['resolution' => $claim->resolution], $claim->branch_id);

        return $claim;
    }

    public function resolve(WarrantyClaim $claim, ?string $notes): WarrantyClaim
    {
        Gate::authorize('warranty.claims');
        $this->branches->authorize($claim->branch_id);
        if ($claim->status !== 'approved') {
            throw ValidationException::withMessages(['status' => __('Only approved claims can be marked as resolved.')]);
        }
        $claim->update(['status' => 'resolved', 'resolution_notes' => trim(($claim->resolution_notes ?? '')."\n".($notes ?? ''))]);
        $this->audit->log('warranty.claim_resolved', $claim, [], [], $claim->branch_id);

        return $claim;
    }
}
