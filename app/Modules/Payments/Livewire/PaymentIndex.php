<?php

namespace App\Modules\Payments\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only register of every money movement (customer receipts, refunds, supplier payments).
 */
#[Title('Payments')]
class PaymentIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $party = '';

    #[Url]
    public string $method = '';

    #[Url]
    public string $direction = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('payments.view');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(BranchAccess $access)
    {
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $query = Payment::query()
            ->with(['customer:id,name', 'supplier:id,name', 'branch:id,code', 'user:id,name'])
            ->whereIn('branch_id', $access->allowedIds())
            ->when(in_array($this->party, ['customer', 'supplier'], true), fn ($q) => $q->where('party_type', $this->party))
            ->when(in_array($this->direction, ['in', 'out'], true), fn ($q) => $q->where('direction', $this->direction))
            ->when($this->method !== '', fn ($q) => $q->where('method', $this->method))
            ->when($date($this->from), fn ($q, $d) => $q->where('paid_at', '>=', $d.' 00:00:00'))
            ->when($date($this->to), fn ($q, $d) => $q->where('paid_at', '<=', $d.' 23:59:59'))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.$this->search.'%')->orWhere('reference', 'like', '%'.$this->search.'%')));

        $totals = (clone $query)->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) as received, COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) as paid")->reorder()->first();

        return view('livewire.payments.index', [
            'payments' => $query->latest('paid_at')->latest('id')->paginate(40),
            'methods' => PaymentMethod::options(),
            'totals' => $totals,
        ]);
    }
}
