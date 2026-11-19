<?php

namespace App\Modules\Branches\Livewire;

use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Inventory\Models\Inventory;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Branch details')]
class BranchShow extends Component
{
    public Branch $branch;

    public function mount(Branch $branch, BranchAccess $access): void
    {
        $this->authorize('branches.view');
        $access->authorize($branch);
        $this->branch = $branch;
    }

    public function render()
    {
        $users = $this->branch->users()->with('roles')->orderBy('name')->get();
        $inventory = Inventory::where('branch_id', $this->branch->id);
        $summary = [
            'products' => (clone $inventory)->where('quantity', '>', 0)->count(),
            'units' => (int) (clone $inventory)->sum('quantity'),
            'reserved' => (int) (clone $inventory)->sum('reserved_quantity'),
            'low' => (clone $inventory)->lowStock()->count(),
        ];

        return view('livewire.branches.show', compact('users', 'summary'));
    }
}
