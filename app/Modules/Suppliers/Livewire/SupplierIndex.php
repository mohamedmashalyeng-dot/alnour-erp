<?php

namespace App\Modules\Suppliers\Livewire;

use App\Modules\Core\Services\AuditLogger;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Services\SupplierLedger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Suppliers')]
class SupplierIndex extends Component
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
        $this->authorize('suppliers.view');
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
        $this->authorize('suppliers.manage');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('suppliers.manage');
        $s = Supplier::findOrFail($id);
        $this->editingId = $s->id;
        $this->form = $s->only(['code', 'name', 'company_name', 'phone', 'email', 'address', 'tax_number', 'notes', 'is_active']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('suppliers.manage');
        $data = $this->validate([
            'form.code' => ['nullable', 'string', 'max:32', 'alpha_dash', Rule::unique('suppliers', 'code')->ignore($this->editingId)],
            'form.name' => ['required', 'string', 'max:255'],
            'form.company_name' => ['nullable', 'string', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:32'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.tax_number' => ['nullable', 'string', 'max:64'],
            'form.notes' => ['nullable', 'string', 'max:2000'],
            'form.is_active' => ['boolean'],
        ])['form'];
        $data['code'] = $data['code'] ?: null;

        if ($this->editingId) {
            $s = Supplier::findOrFail($this->editingId);
            $original = $s->getAttributes();
            $s->update($data);
            $audit->updated($s, 'supplier', $original);
        } else {
            $audit->created(Supplier::create($data), 'supplier');
        }
        $this->showForm = false;
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = ['code' => '', 'name' => '', 'company_name' => '', 'phone' => '', 'email' => '', 'address' => '', 'tax_number' => '', 'notes' => '', 'is_active' => true];
        $this->resetValidation();
    }

    public function render(SupplierLedger $ledger)
    {
        $query = Supplier::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->search}%")
                ->orWhere('company_name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")))
            ->orderBy('name');

        $ledger->withTotals($query);
        if ($this->withBalance) {
            $ledger->onlyWithBalance($query);
        }

        return view('livewire.suppliers.index', ['suppliers' => $query->paginate(25)]);
    }
}
