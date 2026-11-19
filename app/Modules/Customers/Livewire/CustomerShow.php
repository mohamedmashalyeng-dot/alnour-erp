<?php

namespace App\Modules\Customers\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerLedger;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customer profile: purchase history, statement and payment collection (allocated oldest-first).
 * Sales of branches the user cannot access are hidden from the history.
 */
class CustomerShow extends Component
{
    use WithPagination;

    #[Locked]
    public int $customerId;

    #[Locked]
    public string $paymentUuid = '';

    #[Url]
    public string $tab = 'invoices';

    public array $payment = ['method' => 'cash', 'amount' => '', 'reference' => ''];

    public function mount(Customer $customer): void
    {
        $this->authorize('customers.view');
        $this->customerId = $customer->id;
        $this->paymentUuid = (string) Str::uuid();
    }

    public function collect(PaymentService $payments, BranchAccess $access): void
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
        $branch = $access->authorizeOperational((int) $access->currentOperational());
        $payments->receiveOnAccount(Customer::findOrFail($this->customerId), $branch, PaymentMethod::from($data['method']), Money::parse($data['amount']), [
            'uuid' => $this->paymentUuid,
            'reference' => $data['reference'] ?: null,
        ]);
        $this->paymentUuid = (string) Str::uuid();
        $this->payment = ['method' => 'cash', 'amount' => '', 'reference' => ''];
        $this->dispatch('toast', message: __('Payment recorded.'));
    }

    public function render(CustomerLedger $ledger, BranchAccess $access)
    {
        $customer = Customer::findOrFail($this->customerId);
        $canFinance = auth()->user()->can('reports.financial') || auth()->user()->can('payments.record');

        $sales = Sale::with('branch:id,code')
            ->where('customer_id', $customer->id)
            ->whereIn('branch_id', $access->allowedIds())
            ->where('status', '!=', SaleStatus::Draft->value)
            ->latest('sold_at')->paginate(15);

        return view('livewire.customers.show', [
            'customer' => $customer,
            'totals' => $ledger->totals($customer),
            'sales' => $sales,
            'statement' => $this->tab === 'statement' && $canFinance ? $ledger->statement($customer) : null,
            'canFinance' => $canFinance,
        ])->title($customer->name);
    }
}
