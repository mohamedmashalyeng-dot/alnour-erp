<?php

namespace App\Modules\Inventory\Livewire;

use App\Livewire\Concerns\SearchesProducts;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Support\Money;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Opening stock, manual receipts and adjustments. All writes go through InventoryService.
 */
#[Title('Stock operations')]
class StockOperations extends Component
{
    use SearchesProducts;

    #[Url]
    public string $mode = 'receive'; // opening | receive | adjust

    public ?int $branchId = null;

    /** @var list<array{product_id:int, label:string, quantity:string, unit_cost:string}> */
    public array $lines = [];

    public string $reason = '';

    public string $notes = '';

    public string $scan = '';

    // Adjustment
    public string $direction = 'decrease';

    public bool $allowNegative = false;

    public function mount(BranchAccess $access): void
    {
        abort_unless(auth()->user()->canany(['inventory.opening-stock', 'inventory.receive', 'inventory.adjust']), 403);
        $this->branchId = $access->currentOperational();
        $this->ensureModeAllowed();
    }

    public function updatedMode(): void
    {
        $this->ensureModeAllowed();
        $this->resetForm();
    }

    private function ensureModeAllowed(): void
    {
        $map = ['opening' => 'inventory.opening-stock', 'receive' => 'inventory.receive', 'adjust' => 'inventory.adjust'];
        if (! isset($map[$this->mode]) || ! auth()->user()->can($map[$this->mode])) {
            foreach ($map as $mode => $perm) {
                if (auth()->user()->can($perm)) {
                    $this->mode = $mode;

                    return;
                }
            }
            abort(403);
        }
    }

    public function addProduct(int $productId): void
    {
        $product = Product::where('is_active', true)->where('track_stock', true)->findOrFail($productId);
        $this->pushLine($product);
        $this->productSearch = '';
    }

    public function scanCode(): void
    {
        $product = $this->exactProduct($this->scan);
        if (! $product) {
            $this->addError('scan', __('No product found for this code.'));

            return;
        }
        $this->pushLine($product);
        $this->scan = '';
    }

    private function pushLine(Product $product): void
    {
        if ($this->mode === 'adjust') {
            $this->lines = [];
        }
        foreach ($this->lines as $i => $line) {
            if ($line['product_id'] === $product->id) {
                $this->lines[$i]['quantity'] = (string) ((int) $line['quantity'] + 1);

                return;
            }
        }
        $this->lines[] = [
            'product_id' => $product->id,
            'label' => $product->sku.' — '.$product->displayName(),
            'quantity' => '1',
            'unit_cost' => Money::toDecimal($product->cost_price ?: $product->purchase_price),
        ];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function submit(InventoryService $inventory, BranchAccess $access): void
    {
        $this->validate([
            'branchId' => ['required', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => ['nullable', 'string', 'max:20'],
            'reason' => [$this->mode === 'opening' ? 'nullable' : 'required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $access->authorizeOperational($this->branchId);

        $lines = [];
        foreach ($this->lines as $i => $line) {
            $cost = trim((string) $line['unit_cost']);
            if ($cost !== '' && (! Money::isValid($cost) || Money::parse($cost) < 0)) {
                throw ValidationException::withMessages(["lines.$i.unit_cost" => __('Invalid unit cost.')]);
            }
            $lines[] = [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
                'unit_cost' => $cost === '' ? null : Money::parse($cost),
            ];
        }

        match ($this->mode) {
            'opening' => $inventory->recordOpeningStock($this->branchId, $lines, $this->notes ?: null),
            'receive' => $inventory->receiveStock($this->branchId, $lines, $this->reason, $this->notes ?: null),
            'adjust' => $inventory->adjust(
                $this->branchId,
                $lines[0]['product_id'],
                $this->direction === 'increase' ? $lines[0]['quantity'] : -$lines[0]['quantity'],
                $this->reason,
                $this->notes ?: null,
                $this->allowNegative,
            ),
        };

        $this->resetForm();
        $this->dispatch('toast', message: __('Stock updated successfully.'));
    }

    private function resetForm(): void
    {
        $this->lines = [];
        $this->reason = '';
        $this->notes = '';
        $this->allowNegative = false;
        $this->resetValidation();
    }

    public function render(BranchAccess $access)
    {
        $currentStock = [];
        if ($this->branchId && $this->lines) {
            $currentStock = Inventory::where('branch_id', $this->branchId)
                ->whereIn('product_id', array_column($this->lines, 'product_id'))
                ->get()->keyBy('product_id');
        }

        return view('livewire.inventory.operations', [
            'branches' => $access->allowedBranches(),
            'results' => $this->productResults(),
            'currentStock' => $currentStock,
        ]);
    }
}
