<?php

namespace App\Modules\Sales\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SaleShow extends Component
{
    #[Locked]
    public int $saleId;

    /** Idempotency key for the payment form, regenerated after each successful payment. */
    #[Locked]
    public string $paymentUuid = '';

    public array $payment = ['method' => 'cash', 'amount' => '', 'reference' => ''];

    public bool $showPayment = false;

    public bool $showCancel = false;

    public string $cancelReason = '';

    public string $refundMethod = 'cash';

    public function mount(Sale $sale, BranchAccess $access): void
    {
        $this->authorize('sales.view');
        $access->authorize($sale->branch_id);
        abort_if($sale->status === SaleStatus::Draft, 404);
        $this->saleId = $sale->id;
        $this->paymentUuid = (string) Str::uuid();
    }

    public function openPayment(): void
    {
        $this->authorize('payments.record');
        $sale = Sale::findOrFail($this->saleId);
        $this->payment = ['method' => 'cash', 'amount' => Money::toDecimal(max(0, $sale->balanceDue())), 'reference' => ''];
        $this->showPayment = true;
    }

    public function recordPayment(SaleService $sales): void
    {
        $this->authorize('payments.record');
        $data = $this->validate([
            'payment.method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'cheque'])],
            'payment.amount' => ['required', 'string', 'max:20'],
            'payment.reference' => ['nullable', 'string', 'max:100'],
        ])['payment'];
        if (! Money::isValid($data['amount']) || Money::parse($data['amount']) <= 0) {
            $this->addError('payment.amount', __('Invalid amount.'));

            return;
        }
        $sales->addPayment(Sale::findOrFail($this->saleId), [
            'uuid' => $this->paymentUuid,
            'method' => $data['method'],
            'amount' => Money::parse($data['amount']),
            'reference' => $data['reference'] ?: null,
        ]);
        $this->paymentUuid = (string) Str::uuid();
        $this->showPayment = false;
        $this->dispatch('toast', message: __('Payment recorded.'));
    }

    public function cancelSale(SaleService $sales): void
    {
        $this->authorize('sales.cancel');
        $this->validate([
            'cancelReason' => ['required', 'string', 'max:255'],
            'refundMethod' => ['required', Rule::in(['cash', 'card', 'bank_transfer'])],
        ]);
        $sales->cancel(Sale::findOrFail($this->saleId), $this->cancelReason, PaymentMethod::from($this->refundMethod));
        $this->showCancel = false;
        $this->dispatch('toast', message: __('Sale cancelled.'));
    }

    public function render()
    {
        $sale = Sale::with(['items', 'branch', 'customer', 'cashier:id,name', 'returns.items'])->findOrFail($this->saleId);
        $payments = Payment::query()
            ->with('user:id,name')
            ->whereHas('allocations', fn ($q) => $q->where('allocatable_type', 'sale')->where('allocatable_id', $sale->id))
            ->with(['allocations' => fn ($q) => $q->where('allocatable_type', 'sale')->where('allocatable_id', $sale->id)])
            ->orderBy('paid_at')->get();

        $user = auth()->user();

        return view('livewire.sales.show', [
            'sale' => $sale,
            'payments' => $payments,
            'canPay' => $user->can('payments.record') && in_array($sale->status, [SaleStatus::PartiallyPaid], true) && $sale->balanceDue() > 0,
            'canCancel' => $user->can('sales.cancel') && in_array($sale->status, [SaleStatus::Completed, SaleStatus::PartiallyPaid], true) && $sale->return_status === 'none' && $sale->returns->isEmpty(),
            'canReturn' => $user->can('returns.create') && in_array($sale->status, [SaleStatus::Completed, SaleStatus::PartiallyPaid, SaleStatus::Refunded], true) && $sale->items->sum(fn ($i) => $i->returnableQuantity()) > 0,
        ])->title(__('Invoice').' '.$sale->invoice_number);
    }
}
