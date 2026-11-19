<?php

namespace App\Modules\Inventory\Livewire;

use App\Livewire\Concerns\SearchesProducts;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\StockTransferService;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('New transfer')]
class TransferCreate extends Component
{
    use SearchesProducts;

    public ?int $fromBranchId = null;

    public ?int $toBranchId = null;

    public array $lines = [];

    public string $notes = '';

    public string $scan = '';

    public function mount(BranchAccess $access): void
    {
        $this->authorize('transfers.create');
        $this->fromBranchId = $access->currentOperational();
    }

    public function addProduct(int $productId): void
    {
        $this->push(Product::where('is_active', true)->where('track_stock', true)->findOrFail($productId));
        $this->productSearch = '';
    }

    public function scanCode(): void
    {
        $product = $this->exactProduct($this->scan);
        if (! $product) {
            $this->addError('scan', __('No product found for this code.'));

            return;
        }
        $this->push($product);
        $this->scan = '';
    }

    private function push(Product $product): void
    {
        foreach ($this->lines as $i => $line) {
            if ($line['product_id'] === $product->id) {
                $this->lines[$i]['quantity'] = (string) ((int) $line['quantity'] + 1);

                return;
            }
        }
        $this->lines[] = ['product_id' => $product->id, 'label' => $product->sku.' — '.$product->displayName(), 'quantity' => '1'];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function save(StockTransferService $service)
    {
        $this->authorize('transfers.create');
        $this->validate([
            'fromBranchId' => ['required', 'integer'],
            'toBranchId' => ['required', 'integer', 'different:fromBranchId', 'exists:branches,id'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $transfer = $service->create(
            $this->fromBranchId,
            $this->toBranchId,
            array_map(fn ($l) => ['product_id' => (int) $l['product_id'], 'quantity' => (int) $l['quantity']], $this->lines),
            $this->notes ?: null,
        );

        session()->flash('success', __('Transfer :number created.', ['number' => $transfer->number]));

        return $this->redirectRoute('transfers.show', $transfer);
    }

    public function render(BranchAccess $access)
    {
        $available = [];
        if ($this->fromBranchId && $this->lines && $access->canAccess($this->fromBranchId)) {
            $available = Inventory::where('branch_id', $this->fromBranchId)
                ->whereIn('product_id', array_column($this->lines, 'product_id'))->get()->keyBy('product_id');
        }

        return view('livewire.inventory.transfers.create', [
            'fromBranches' => $access->allowedBranches(),
            'toBranches' => Branch::active()->where('id', '!=', $this->fromBranchId)->orderBy('code')->get(),
            'results' => $this->productResults(),
            'available' => $available,
        ]);
    }
}
