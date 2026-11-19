<?php

namespace App\Modules\Purchasing\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Purchase orders')]
class PurchaseIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $payment = '';

    public function mount(): void
    {
        $this->authorize('purchases.view');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(BranchAccess $access)
    {
        $orders = PurchaseOrder::query()
            ->with(['supplier:id,name', 'branch:id,code', 'creator:id,name'])
            ->whereIn('branch_id', $access->allowedIds())
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.$this->search.'%')
                ->orWhere('supplier_invoice_number', 'like', '%'.$this->search.'%')
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', '%'.$this->search.'%'))))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->payment !== '', fn ($q) => $q->where('payment_status', $this->payment))
            ->latest('id')->paginate(25);

        return view('livewire.purchasing.index', [
            'orders' => $orders,
            'statuses' => PurchaseStatus::options(),
            'paymentStatuses' => PaymentStatus::options(),
        ]);
    }
}
