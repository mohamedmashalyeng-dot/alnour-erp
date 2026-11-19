<?php

namespace App\Modules\Sales\Livewire;

use App\Models\User;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Sales history')]
class SaleIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $branch = null;

    #[Url]
    public string $status = '';

    #[Url]
    public string $payment = '';

    /** Cashier transaction history filter. */
    #[Url]
    public string $cashier = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(BranchAccess $access): void
    {
        $this->authorize('sales.view');
        $this->branch ??= $access->current();
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(BranchAccess $access)
    {
        $branchIds = $this->branch ? [$this->branch] : $access->allowedIds();
        if ($this->branch) {
            $access->authorize($this->branch);
        } else {
            abort_unless(auth()->user()->canAccessAllBranches(), 403);
        }
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        $sales = Sale::query()
            ->with(['branch:id,code,name,name_en', 'customer:id,name,phone', 'cashier:id,name'])
            ->whereIn('branch_id', $branchIds)
            ->where('status', '!=', SaleStatus::Draft->value)
            ->when($this->search !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('invoice_number', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
            })
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->payment !== '', fn ($q) => $q->where('payment_status', $this->payment))
            ->when(ctype_digit($this->cashier), fn ($q) => $q->where('cashier_id', (int) $this->cashier))
            ->when($date($this->from), fn ($q, $d) => $q->where('sold_at', '>=', $d.' 00:00:00'))
            ->when($date($this->to), fn ($q, $d) => $q->where('sold_at', '<=', $d.' 23:59:59'))
            ->latest('sold_at')->latest('id')
            ->paginate(30);

        return view('livewire.sales.index', [
            'sales' => $sales,
            'branches' => $access->allowedBranches(activeOnly: false),
            'statuses' => array_diff_key(SaleStatus::options(), ['draft' => 1]),
            'paymentStatuses' => PaymentStatus::options(),
            'cashiers' => User::query()->whereIn('id', Sale::query()->select('cashier_id')->whereIn('branch_id', $branchIds)->distinct())
                ->orderBy('name')->get(['id', 'name']),
            'canAll' => auth()->user()->canAccessAllBranches(),
        ]);
    }
}
