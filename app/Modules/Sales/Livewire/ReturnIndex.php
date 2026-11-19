<?php

namespace App\Modules\Sales\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Sales\Enums\SaleReturnStatus;
use App\Modules\Sales\Models\SaleReturn;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Returns & exchanges')]
class ReturnIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('returns.create');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(BranchAccess $access)
    {
        $returns = SaleReturn::query()
            ->with(['sale:id,invoice_number', 'customer:id,name', 'branch:id,code', 'user:id,name'])
            ->whereIn('branch_id', $access->allowedIds())
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('sale', fn ($s) => $s->where('invoice_number', 'like', '%'.$this->search.'%'))))
            ->latest('id')->paginate(25);

        return view('livewire.sales.returns.index', ['returns' => $returns, 'statuses' => SaleReturnStatus::options()]);
    }
}
