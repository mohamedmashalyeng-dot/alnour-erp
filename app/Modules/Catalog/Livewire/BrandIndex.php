<?php

namespace App\Modules\Catalog\Livewire;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Brands')]
class BrandIndex extends Component
{
    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public array $form = ['name' => '', 'name_en' => '', 'is_active' => true];

    public function mount(): void
    {
        $this->authorize('brands.manage');
    }

    public function create(): void
    {
        $this->authorize('brands.manage');
        $this->editingId = null;
        $this->form = ['name' => '', 'name_en' => '', 'is_active' => true];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('brands.manage');
        $b = Brand::findOrFail($id);
        $this->editingId = $b->id;
        $this->form = ['name' => $b->name, 'name_en' => $b->name_en, 'is_active' => $b->is_active];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('brands.manage');
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255', Rule::unique('brands', 'name')->ignore($this->editingId)],
            'form.name_en' => ['nullable', 'string', 'max:255'],
            'form.is_active' => ['boolean'],
        ])['form'];

        if ($this->editingId) {
            $b = Brand::findOrFail($this->editingId);
            $original = $b->getAttributes();
            $b->update($data);
            $audit->updated($b, 'brand', $original);
        } else {
            $audit->created(Brand::create($data), 'brand');
        }
        $this->showForm = false;
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function toggle(int $id, AuditLogger $audit): void
    {
        $this->authorize('brands.manage');
        $b = Brand::findOrFail($id);
        $b->update(['is_active' => ! $b->is_active]);
        $audit->log('brand.'.($b->is_active ? 'activated' : 'deactivated'), $b);
    }

    public function render()
    {
        return view('livewire.catalog.brands', ['brands' => Brand::withCount('products')->orderBy('name')->get()]);
    }
}
