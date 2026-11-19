<?php

namespace App\Modules\Branches\Livewire;

use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Branches')]
class BranchIndex extends Component
{
    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        $this->authorize('branches.view');
        $this->resetForm();
    }

    public function create(): void
    {
        $this->authorize('branches.manage');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('branches.manage');
        $branch = Branch::findOrFail($id);
        $this->editingId = $branch->id;
        $this->form = $branch->only(['code', 'name', 'name_en', 'phone', 'email', 'address', 'city', 'tax_number', 'notes', 'is_active']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit, BranchAccess $access): void
    {
        $this->authorize('branches.manage');
        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:16', 'alpha_dash', Rule::unique('branches', 'code')->ignore($this->editingId)],
            'form.name' => ['required', 'string', 'max:255'],
            'form.name_en' => ['nullable', 'string', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:32'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.city' => ['nullable', 'string', 'max:255'],
            'form.tax_number' => ['nullable', 'string', 'max:64'],
            'form.notes' => ['nullable', 'string', 'max:2000'],
            'form.is_active' => ['boolean'],
        ])['form'];
        $data['code'] = strtoupper($data['code']);

        if ($this->editingId) {
            $branch = Branch::findOrFail($this->editingId);
            $original = $branch->getAttributes();
            $branch->update($data);
            $audit->updated($branch, 'branch', $original);
        } else {
            $branch = Branch::create($data);
            $audit->created($branch, 'branch');
        }
        $access->flush();
        $this->showForm = false;
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function toggle(int $id, AuditLogger $audit): void
    {
        $this->authorize('branches.manage');
        $branch = Branch::findOrFail($id);
        $branch->update(['is_active' => ! $branch->is_active]);
        $audit->log('branch.'.($branch->is_active ? 'activated' : 'deactivated'), $branch, [], ['is_active' => $branch->is_active], $branch->id);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = ['code' => '', 'name' => '', 'name_en' => '', 'phone' => '', 'email' => '', 'address' => '', 'city' => '', 'tax_number' => '', 'notes' => '', 'is_active' => true];
        $this->resetValidation();
    }

    public function render(BranchAccess $access)
    {
        $branches = Branch::query()
            ->whereIn('id', $access->allowedIds())
            ->withCount('users')
            ->withSum('inventory as units', 'quantity')
            ->orderBy('code')
            ->get();

        return view('livewire.branches.index', compact('branches'));
    }
}
