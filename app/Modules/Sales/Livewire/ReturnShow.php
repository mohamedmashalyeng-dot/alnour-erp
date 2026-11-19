<?php

namespace App\Modules\Sales\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Sales\Enums\ReturnDisposition;
use App\Modules\Sales\Enums\SaleReturnStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Services\ReturnService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ReturnShow extends Component
{
    #[Locked]
    public int $returnId;

    #[Locked]
    public string $refundUuid = '';

    public array $dispositions = [];

    public string $refundMethod = 'cash';

    public string $refundAmount = '';

    public string $refundReference = '';

    public string $rejectReason = '';

    public function mount(SaleReturn $saleReturn, BranchAccess $access): void
    {
        $this->authorize('returns.create');
        $access->authorize($saleReturn->branch_id);
        $this->returnId = $saleReturn->id;
        $this->refundUuid = (string) Str::uuid();
        foreach ($saleReturn->items as $item) {
            $this->dispositions[$item->id] = $item->disposition->value;
        }
        $this->refundAmount = Money::toDecimal($this->available($saleReturn));
    }

    private function available(SaleReturn $r): int
    {
        $sale = Sale::find($r->sale_id);

        return max(0, min($r->total - $r->refunded_amount - $r->exchange_credit_used, $sale?->refundable() ?? 0));
    }

    public function complete(ReturnService $service): void
    {
        $this->validate(['dispositions.*' => [Rule::in(ReturnDisposition::values())]]);
        $return = $service->complete(SaleReturn::findOrFail($this->returnId), $this->dispositions);
        $this->refundAmount = Money::toDecimal($this->available($return->refresh()));
        $this->dispatch('toast', message: __('Return completed.'));
    }

    public function reject(ReturnService $service): void
    {
        $this->validate(['rejectReason' => ['required', 'string', 'max:255']]);
        $service->reject(SaleReturn::findOrFail($this->returnId), $this->rejectReason);
        $this->dispatch('toast', message: __('Return rejected.'));
    }

    public function refund(ReturnService $service): void
    {
        $this->validate([
            'refundMethod' => ['required', Rule::in(['cash', 'card', 'bank_transfer'])],
            'refundAmount' => ['required', 'string', 'max:20'],
            'refundReference' => ['nullable', 'string', 'max:100'],
        ]);
        if (! Money::isValid($this->refundAmount) || Money::parse($this->refundAmount) <= 0) {
            $this->addError('refundAmount', __('Invalid amount.'));

            return;
        }
        $service->refund(SaleReturn::findOrFail($this->returnId), PaymentMethod::from($this->refundMethod), Money::parse($this->refundAmount), $this->refundReference ?: null, $this->refundUuid);
        $this->refundUuid = (string) Str::uuid();
        $this->refundAmount = Money::toDecimal($this->available(SaleReturn::findOrFail($this->returnId)));
        $this->dispatch('toast', message: __('Refund recorded.'));
    }

    public function render()
    {
        $return = SaleReturn::with(['items.saleItem', 'items.product:id,name,name_en,sku', 'sale', 'customer', 'branch', 'user:id,name', 'inspector:id,name', 'payments', 'exchangeSale:id,invoice_number'])->findOrFail($this->returnId);
        $user = auth()->user();

        return view('livewire.sales.returns.show', [
            'r' => $return,
            'pending' => $return->status === SaleReturnStatus::Pending,
            'canApprove' => $user->can('returns.approve') && $return->status === SaleReturnStatus::Pending,
            'canRefund' => $user->can('refunds.process') && $return->status === SaleReturnStatus::Completed && $this->available($return) > 0,
            'available' => $this->available($return),
            'dispositionOptions' => ReturnDisposition::options(),
        ])->title(__('Return').' '.$return->number);
    }
}
