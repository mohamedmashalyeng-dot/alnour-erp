<?php

namespace App\Modules\Sales\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Sales\Enums\ItemCondition;
use App\Modules\Sales\Enums\ReturnDisposition;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\ReturnService;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('New return')]
class ReturnCreate extends Component
{
    #[Url]
    public string $invoice = '';

    #[Locked]
    public ?int $saleId = null;

    #[Locked]
    public string $uuid = '';

    /** @var array<int, array{qty:string, condition:string, disposition:string}> keyed by sale_item_id */
    public array $lines = [];

    public string $reason = '';

    public string $type = 'return';

    public string $notes = '';

    public bool $completeNow = true;

    public function mount(): void
    {
        $this->authorize('returns.create');
        $this->uuid = (string) Str::uuid();
        if ($this->invoice !== '') {
            $this->find(app(BranchAccess::class));
        }
    }

    public function find(BranchAccess $access): void
    {
        $this->saleId = null;
        $this->lines = [];
        $sale = Sale::with('items')->where('invoice_number', trim($this->invoice))->first();
        if (! $sale || ! $access->canAccess($sale->branch_id)) {
            $this->addError('invoice', __('Invoice not found.'));

            return;
        }
        if (! in_array($sale->status, [SaleStatus::Completed, SaleStatus::PartiallyPaid, SaleStatus::Refunded], true)) {
            $this->addError('invoice', __('Returns are only possible for completed invoices.'));

            return;
        }
        $this->saleId = $sale->id;
        foreach ($sale->items as $item) {
            $this->lines[$item->id] = ['qty' => '0', 'condition' => ItemCondition::Good->value, 'disposition' => ReturnDisposition::Restock->value];
        }
    }

    public function submit(ReturnService $returns)
    {
        $this->authorize('returns.create');
        $this->validate([
            'reason' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'integer', 'min:0'],
        ]);
        $sale = Sale::findOrFail($this->saleId);
        $payload = [];
        foreach ($this->lines as $itemId => $line) {
            if ((int) $line['qty'] > 0) {
                $payload[] = ['sale_item_id' => (int) $itemId, 'quantity' => (int) $line['qty'], 'condition' => $line['condition'], 'disposition' => $line['disposition']];
            }
        }
        if ($payload === []) {
            $this->addError('lines', __('Enter a quantity for at least one item.'));

            return null;
        }

        $return = $returns->create($sale, $payload, $this->reason, $this->type, $this->notes ?: null, $this->uuid);
        if ($this->completeNow && auth()->user()->can('returns.approve')) {
            $returns->complete($return);
        }

        session()->flash('success', __('Return :number recorded.', ['number' => $return->number]));

        return $this->redirectRoute('returns.show', $return);
    }

    public function render()
    {
        $sale = $this->saleId ? Sale::with(['items', 'customer', 'branch'])->find($this->saleId) : null;

        return view('livewire.sales.returns.create', [
            'sale' => $sale,
            'conditions' => ItemCondition::options(),
            'dispositions' => ReturnDisposition::options(),
        ]);
    }
}
