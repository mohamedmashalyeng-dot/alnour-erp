<?php

namespace App\Modules\Customers\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerLedger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Customers')]
class CustomerIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public bool $withBalance = false;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        $this->authorize('customers.view');
        $this->resetForm();
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'withBalance'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('customers.manage');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('customers.manage');
        $c = Customer::findOrFail($id);
        $this->editingId = $c->id;
        $this->form = $c->only(['name', 'phone', 'email', 'address', 'notes', 'is_active']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit, BranchAccess $access): void
    {
        $this->authorize('customers.manage');
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:32', Rule::unique('customers', 'phone')->ignore($this->editingId)],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.notes' => ['nullable', 'string', 'max:2000'],
            'form.is_active' => ['boolean'],
        ])['form'];
        $data['phone'] = $data['phone'] ?: null;

        if ($this->editingId) {
            $c = Customer::findOrFail($this->editingId);
            $original = $c->getAttributes();
            $c->update($data);
            $audit->updated($c, 'customer', $original);
        } else {
            $c = Customer::create($data + ['branch_id' => $access->currentOperational(), 'created_by' => auth()->id()]);
            $audit->created($c, 'customer');
        }
        $this->showForm = false;
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'phone' => '', 'email' => '', 'address' => '', 'notes' => '', 'is_active' => true];
        $this->resetValidation();
    }

    public function render(CustomerLedger $ledger)
    {
        $query = Customer::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
            ->orderBy('name');
        $ledger->withTotals($query);
        if ($this->withBalance) {
            $ledger->onlyWithBalance($query);
        }

        return view('livewire.customers.index', ['customers' => $query->paginate(25), 'canFinance' => auth()->user()->can('reports.financial') || auth()->user()->can('payments.record')]);
    }
}
