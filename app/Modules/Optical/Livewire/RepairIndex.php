<?php

namespace App\Modules\Optical\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\Customers\Models\Customer;
use App\Modules\Optical\Models\RepairOrder;
use App\Modules\Optical\Models\WarrantyClaim;
use App\Modules\Optical\Services\RepairService;
use App\Modules\Payments\Enums\PaymentMethod;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Repair orders: intake, status workflow and delivery (charged repairs are invoiced via the sales engine). */
#[Title('Repairs')]
class RepairIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'open';

    public bool $showForm = false;

    public string $customerSearch = '';

    public array $form = [];

    #[Locked]
    public ?int $delivering = null;

    public array $delivery = ['final_cost' => '', 'method' => 'cash', 'amount' => '', 'reference' => ''];

    public function mount(): void
    {
        $this->authorize('repairs.view');
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->form = ['branch_id' => (string) app(BranchAccess::class)->currentOperational(), 'customer_id' => '', 'warranty_claim_id' => '',
            'item_description' => '', 'problem' => '', 'estimated_cost' => '', 'promised_on' => '', 'notes' => ''];
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->authorize('repairs.manage');
        $this->resetForm();
        $this->showForm = true;
    }

    public function pickCustomer(int $id): void
    {
        $this->form['customer_id'] = (string) $id;
        $this->customerSearch = '';
    }

    public function save(RepairService $service): void
    {
        $cost = trim((string) $this->form['estimated_cost']);
        if ($cost !== '' && ! Money::isValid($cost)) {
            throw ValidationException::withMessages(['form.estimated_cost' => __('Invalid amount.')]);
        }
        $data = ['branch_id' => (int) $this->form['branch_id'], 'customer_id' => (int) $this->form['customer_id'] ?: null,
            'warranty_claim_id' => (int) $this->form['warranty_claim_id'] ?: null, 'item_description' => $this->form['item_description'],
            'problem' => $this->form['problem'], 'estimated_cost' => $cost === '' ? 0 : Money::parse($cost),
            'promised_on' => $this->form['promised_on'] ?: null, 'notes' => $this->form['notes'] ?: null];
        try {
            $order = $service->create($data);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['form.'.$k => $m])->all());
        }
        $this->showForm = false;
        $this->dispatch('toast', message: __('Repair :number registered.', ['number' => $order->number]));
    }

    public function move(int $id, string $status, RepairService $service): void
    {
        try {
            $service->setStatus(RepairOrder::findOrFail($id), $status);
        } catch (ValidationException $e) {
            $this->addError("repair.$id", collect($e->errors())->flatten()->first());
        }
    }

    public function startDelivery(int $id): void
    {
        $this->authorize('repairs.manage');
        $order = RepairOrder::findOrFail($id);
        app(BranchAccess::class)->authorize($order->branch_id);
        $this->delivering = $id;
        $cost = $order->warranty_claim_id ? '0' : money_input($order->estimated_cost);
        $this->delivery = ['final_cost' => $cost, 'method' => 'cash', 'amount' => $cost, 'reference' => ''];
    }

    public function deliver(RepairService $service): void
    {
        $cost = trim((string) $this->delivery['final_cost']);
        $amount = trim((string) $this->delivery['amount']);
        if (! Money::isValid($cost) || ($amount !== '' && ! Money::isValid($amount))) {
            throw ValidationException::withMessages(['delivery.final_cost' => __('Invalid amount.')]);
        }
        $payments = [];
        if ($amount !== '' && Money::parse($amount) > 0) {
            $payments[] = array_filter(['method' => $this->delivery['method'], 'amount' => Money::parse($amount), 'reference' => $this->delivery['reference'] ?: null]);
        }
        try {
            $order = $service->deliver(RepairOrder::findOrFail($this->delivering), Money::parse($cost), $payments);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['delivery.'.$k => $m])->all());
        }
        $this->delivering = null;
        $this->dispatch('toast', message: __('Repair delivered.'));
        if ($order->sale_id) {
            $this->dispatch('pos-sale-completed', receiptUrl: route('sales.receipt', $order->sale_id));
        }
    }

    public function render(BranchAccess $access)
    {
        $ids = $access->allowedIds();

        return view('livewire.optical.repairs', [
            'orders' => RepairOrder::with(['customer:id,name,phone', 'branch:id,code', 'sale:id,invoice_number', 'claim:id,number'])->whereIn('branch_id', $ids)
                ->when($this->status === 'open', fn ($q) => $q->whereIn('status', ['received', 'in_progress', 'ready']))
                ->when(! in_array($this->status, ['open', ''], true), fn ($q) => $q->where('status', $this->status))
                ->latest('id')->paginate(25),
            'branches' => $access->allowedBranches(),
            'customerResults' => mb_strlen(trim($this->customerSearch)) >= 2
                ? Customer::where('is_active', true)->where('is_walk_in', false)->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->customerSearch).'%')->orWhere('phone', 'like', '%'.trim($this->customerSearch).'%'))->limit(8)->get(['id', 'name', 'phone'])
                : collect(),
            'selectedCustomer' => $this->form['customer_id'] ? Customer::find((int) $this->form['customer_id'], ['id', 'name', 'phone']) : null,
            'claims' => $this->form['customer_id'] ? WarrantyClaim::where('status', 'approved')->where('resolution', 'repair')
                ->whereHas('warranty', fn ($q) => $q->where('customer_id', (int) $this->form['customer_id']))->get(['id', 'number']) : collect(),
            'methods' => array_filter(PaymentMethod::cases(), fn ($m) => $m !== PaymentMethod::StoreCredit),
        ]);
    }
}
