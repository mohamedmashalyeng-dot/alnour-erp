<?php

namespace App\Modules\Optical\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Customers\Models\Customer;
use App\Modules\Optical\Models\Prescription;
use App\Modules\Optical\Services\PrescriptionService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** Prescriptions list + new prescription form. Restricted to prescriptions.view / prescriptions.manage. */
#[Title('Prescriptions')]
class PrescriptionIndex extends Component
{
    use WithFileUploads, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $customer = null;

    public bool $showForm = false;

    public string $customerSearch = '';

    public array $form = [];

    public $file = null;

    public function mount(): void
    {
        $this->authorize('prescriptions.view');
        $this->resetForm();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    private function resetForm(): void
    {
        $this->form = ['customer_id' => $this->customer ? (string) $this->customer : '', 'branch_id' => (string) app(BranchAccess::class)->currentOperational(),
            'type' => 'spectacles', 'examined_on' => today()->toDateString(), 'expires_on' => today()->addYear()->toDateString(), 'examiner_name' => auth()->user()->name,
            'od_sph' => '', 'od_cyl' => '', 'od_axis' => '', 'od_add' => '', 'os_sph' => '', 'os_cyl' => '', 'os_axis' => '', 'os_add' => '',
            'pd' => '', 'pd_right' => '', 'pd_left' => '', 'cl_base_curve' => '', 'cl_diameter' => '', 'medical_notes' => ''];
        $this->file = null;
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->authorize('prescriptions.manage');
        $this->resetForm();
        $this->showForm = true;
    }

    public function pickCustomer(int $id): void
    {
        $this->form['customer_id'] = (string) $id;
        $this->customerSearch = '';
    }

    public function save(PrescriptionService $service)
    {
        $customer = Customer::find((int) $this->form['customer_id']);
        if (! $customer) {
            throw ValidationException::withMessages(['form.customer_id' => __('Choose a customer.')]);
        }
        try {
            $rx = $service->create($customer, $this->form, $this->file);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [($k === 'file' ? 'file' : 'form.'.$k) => $m])->all());
        }
        $this->showForm = false;
        session()->flash('status', __('Prescription :number saved.', ['number' => $rx->number]));

        return $this->redirectRoute('optical.prescriptions.show', $rx, navigate: false);
    }

    public function render(BranchAccess $access)
    {
        $term = trim($this->search);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return view('livewire.optical.prescriptions', [
            'prescriptions' => Prescription::with(['customer:id,name,phone', 'branch:id,code'])->whereIn('branch_id', $access->allowedIds())
                ->when($this->customer, fn ($q) => $q->where('customer_id', $this->customer))
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like))))
                ->latest('id')->paginate(25),
            'customerResults' => mb_strlen(trim($this->customerSearch)) >= 2
                ? Customer::where('is_active', true)->where('is_walk_in', false)->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->customerSearch).'%')->orWhere('phone', 'like', '%'.trim($this->customerSearch).'%'))->limit(8)->get(['id', 'name', 'phone'])
                : collect(),
            'selectedCustomer' => $this->form['customer_id'] ? Customer::find((int) $this->form['customer_id'], ['id', 'name', 'phone']) : null,
            'branches' => $access->allowedBranches(),
        ]);
    }
}
