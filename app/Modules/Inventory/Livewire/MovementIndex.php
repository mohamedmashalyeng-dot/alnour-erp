<?php

namespace App\Modules\Inventory\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryTransaction;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Stock movements')]
class MovementIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $branch = null;

    #[Url]
    public string $type = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(BranchAccess $access): void
    {
        $this->authorize('inventory.view-movements');
        $this->branch ??= $access->current();
    }

    public function updating($name): void
    {
        $this->resetPage();
    }

    public function render(BranchAccess $access)
    {
        if ($this->branch) {
            $access->authorize($this->branch);
        } else {
            abort_unless(auth()->user()->canAccessAllBranches(), 403);
        }

        $from = $this->validDate($this->from);
        $to = $this->validDate($this->to);

        $movements = InventoryTransaction::query()
            ->with(['product:id,name,name_en,sku', 'branch:id,code,name,name_en', 'user:id,name'])
            ->whereIn('branch_id', $this->branch ? [$this->branch] : $access->allowedIds())
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->search !== '', fn ($q) => $q->whereHas('product', fn ($p) => $p->search($this->search)))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->latest('id')
            ->paginate(40);

        return view('livewire.inventory.movements', [
            'movements' => $movements,
            'branches' => $access->allowedBranches(activeOnly: false),
            'types' => MovementType::options(),
            'canAll' => auth()->user()->canAccessAllBranches(),
        ]);
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
