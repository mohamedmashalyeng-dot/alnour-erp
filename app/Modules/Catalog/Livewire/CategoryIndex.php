<?php

namespace App\Modules\Catalog\Livewire;

use App\Modules\Catalog\Models\Category;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Categories')]
class CategoryIndex extends Component
{
    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public array $form = ['name' => '', 'name_en' => '', 'code' => '', 'parent_id' => '', 'is_active' => true];

    public function mount(): void
    {
        $this->authorize('categories.manage');
    }

    public function create(): void
    {
        $this->authorize('categories.manage');
        $this->editingId = null;
        $this->form = ['name' => '', 'name_en' => '', 'code' => '', 'parent_id' => '', 'is_active' => true];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('categories.manage');
        $c = Category::findOrFail($id);
        $this->editingId = $c->id;
        $this->form = ['name' => $c->name, 'name_en' => $c->name_en, 'code' => $c->code, 'parent_id' => (string) $c->parent_id, 'is_active' => $c->is_active];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('categories.manage');
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.name_en' => ['nullable', 'string', 'max:255'],
            'form.code' => ['nullable', 'string', 'max:32', 'alpha_dash', Rule::unique('categories', 'code')->ignore($this->editingId)],
            'form.parent_id' => ['nullable', 'integer', 'exists:categories,id', Rule::notIn(array_filter([$this->editingId]))],
            'form.is_active' => ['boolean'],
        ])['form'];
        $data['parent_id'] = $data['parent_id'] ?: null;
        $data['code'] = $data['code'] ?: null;

        if ($this->editingId) {
            $c = Category::findOrFail($this->editingId);
            $original = $c->getAttributes();
            $c->update($data);
            $audit->updated($c, 'category', $original);
        } else {
            $audit->created(Category::create($data), 'category');
        }
        $this->showForm = false;
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function toggle(int $id, AuditLogger $audit): void
    {
        $this->authorize('categories.manage');
        $c = Category::findOrFail($id);
        $c->update(['is_active' => ! $c->is_active]);
        $audit->log('category.'.($c->is_active ? 'activated' : 'deactivated'), $c);
    }

    public function render()
    {
        return view('livewire.catalog.categories', [
            'categories' => Category::with('parent')->withCount('products')->orderBy('name')->get(),
        ]);
    }
}
