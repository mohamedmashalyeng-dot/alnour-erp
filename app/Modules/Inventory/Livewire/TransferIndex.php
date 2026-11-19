<?php

namespace App\Modules\Inventory\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockTransfer;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Stock transfers')]
class TransferIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('transfers.view');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(BranchAccess $access)
    {
        $ids = $access->allowedIds();
        $transfers = StockTransfer::query()
            ->with(['fromBranch:id,code,name,name_en', 'toBranch:id,code,name,name_en', 'creator:id,name'])
            ->withSum('items as units', 'quantity')
            ->where(fn ($q) => $q->whereIn('from_branch_id', $ids)->orWhereIn('to_branch_id', $ids))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where('number', 'like', '%'.$this->search.'%'))
            ->latest('id')
            ->paginate(25);

        return view('livewire.inventory.transfers.index', [
            'transfers' => $transfers,
            'statuses' => TransferStatus::options(),
        ]);
    }
}
