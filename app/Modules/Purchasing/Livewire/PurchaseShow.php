<?php

namespace App\Modules\Purchasing\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PurchaseShow extends Component
{
    #[Locked]
    public int $purchaseId;

    #[Locked]
    public string $paymentUuid = '';

    public array $payment = ['method' => 'bank_transfer', 'amount' => '', 'reference' => ''];

    public bool $showPayment = false;

    public bool $showCancel = false;

    public string $cancelReason = '';

    public function mount(PurchaseOrder $purchase, BranchAccess $access): void
    {
        $this->authorize('purchases.view');
        $access->authorize($purchase->branch_id);
        $this->purchaseId = $purchase->id;
        $this->paymentUuid = (string) Str::uuid();
    }

    private function po(): PurchaseOrder
    {
        return PurchaseOrder::findOrFail($this->purchaseId);
    }

    public function confirm(PurchaseService $service): void
    {
        $service->confirm($this->po());
        $this->dispatch('toast', message: __('Purchase order confirmed.'));
    }

    public function receive(PurchaseService $service): void
    {
        $service->receive($this->po());
        $this->dispatch('toast', message: __('Goods received into stock.'));
    }

    public function cancel(PurchaseService $service): void
    {
        $this->validate(['cancelReason' => ['required', 'string', 'max:255']]);
        $service->cancel($this->po(), $this->cancelReason);
        $this->showCancel = false;
        $this->dispatch('toast', message: __('Purchase order cancelled.'));
    }

    public function openPayment(): void
    {
        $this->authorize('supplier-payments.record');
        $this->payment = ['method' => 'bank_transfer', 'amount' => Money::toDecimal($this->po()->balanceDue()), 'reference' => ''];
        $this->showPayment = true;
    }

    public function pay(PaymentService $payments, BranchAccess $access): void
    {
        $this->authorize('supplier-payments.record');
        $data = $this->validate([
            'payment.method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'cheque'])],
            'payment.amount' => ['required', 'string', 'max:20'],
            'payment.reference' => ['nullable', 'string', 'max:100'],
        ])['payment'];
        if (! Money::isValid($data['amount']) || Money::parse($data['amount']) <= 0) {
            $this->addError('payment.amount', __('Invalid amount.'));

            return;
        }
        $po = PurchaseOrder::with('supplier', 'branch')->findOrFail($this->purchaseId);
        $access->authorizeOperational($po->branch_id);
        $payments->paySupplier($po->supplier, $po->branch, PaymentMethod::from($data['method']), [$po->id => Money::parse($data['amount'])], [
            'uuid' => $this->paymentUuid, 'reference' => $data['reference'] ?: null,
        ]);
        $this->paymentUuid = (string) Str::uuid();
        $this->showPayment = false;
        $this->dispatch('toast', message: __('Payment recorded.'));
    }

    public function render()
    {
        $po = PurchaseOrder::with(['items.product:id,name,name_en,sku', 'supplier', 'branch', 'creator:id,name', 'receiver:id,name'])->findOrFail($this->purchaseId);
        $payments = Payment::with(['user:id,name', 'allocations' => fn ($q) => $q->where('allocatable_type', 'purchase_order')->where('allocatable_id', $po->id)])
            ->whereHas('allocations', fn ($q) => $q->where('allocatable_type', 'purchase_order')->where('allocatable_id', $po->id))
            ->orderBy('paid_at')->get();
        $user = auth()->user();

        return view('livewire.purchasing.show', [
            'po' => $po,
            'payments' => $payments,
            'canEdit' => $po->status === PurchaseStatus::Draft && $user->can('purchases.create'),
            'canConfirm' => $po->status === PurchaseStatus::Draft && $user->can('purchases.create'),
            'canReceive' => $po->status === PurchaseStatus::Ordered && $user->can('purchases.receive'),
            'canCancel' => in_array($po->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered], true) && $po->paid_amount === 0 && $user->can('purchases.cancel'),
            'canPay' => in_array($po->status, [PurchaseStatus::Ordered, PurchaseStatus::Received], true) && $po->balanceDue() > 0 && $user->can('supplier-payments.record'),
        ])->title(__('Purchase order').' '.$po->number);
    }
}
