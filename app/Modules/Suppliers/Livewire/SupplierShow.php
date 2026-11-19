<?php

namespace App\Modules\Suppliers\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Services\SupplierLedger;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class SupplierShow extends Component
{
    #[Locked]
    public int $supplierId;

    #[Locked]
    public string $paymentUuid = '';

    #[Url]
    public string $tab = 'statement';

    public array $payment = ['method' => 'bank_transfer', 'amount' => '', 'reference' => '', 'branch_id' => ''];

    public function mount(Supplier $supplier, BranchAccess $access): void
    {
        $this->authorize('suppliers.view');
        $this->supplierId = $supplier->id;
        $this->paymentUuid = (string) Str::uuid();
        $this->payment['branch_id'] = (string) $access->currentOperational();
    }

    public function pay(PaymentService $payments, BranchAccess $access): void
    {
        $this->authorize('supplier-payments.record');
        $data = $this->validate([
            'payment.method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'cheque'])],
            'payment.amount' => ['required', 'string', 'max:20'],
            'payment.reference' => ['nullable', 'string', 'max:100'],
            'payment.branch_id' => ['required', 'integer'],
        ])['payment'];
        if (! Money::isValid($data['amount']) || Money::parse($data['amount']) <= 0) {
            $this->addError('payment.amount', __('Invalid amount.'));

            return;
        }
        $branch = $access->authorizeOperational((int) $data['branch_id']);
        $payments->paySupplierOnAccount(Supplier::findOrFail($this->supplierId), $branch, PaymentMethod::from($data['method']), Money::parse($data['amount']), [
            'uuid' => $this->paymentUuid,
            'reference' => $data['reference'] ?: null,
        ]);
        $this->paymentUuid = (string) Str::uuid();
        $this->payment['amount'] = '';
        $this->payment['reference'] = '';
        $this->dispatch('toast', message: __('Payment recorded.'));
    }

    public function render(SupplierLedger $ledger, BranchAccess $access)
    {
        $supplier = Supplier::findOrFail($this->supplierId);
        $canFinance = auth()->user()->can('reports.financial') || auth()->user()->can('supplier-payments.record');

        return view('livewire.suppliers.show', [
            'supplier' => $supplier,
            'totals' => $ledger->totals($supplier),
            'statement' => $canFinance && $this->tab === 'statement' ? $ledger->statement($supplier) : null,
            'orders' => PurchaseOrder::with('branch:id,code')->where('supplier_id', $supplier->id)->whereIn('branch_id', $access->allowedIds())->latest('id')->limit(50)->get(),
            'canFinance' => $canFinance,
            'branches' => $access->allowedBranches(),
        ])->title($supplier->name);
    }
}
