<?php

namespace App\Modules\Purchasing\Livewire;

use App\Livewire\Concerns\SearchesProducts;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\TaxCalculator;
use App\Modules\Core\Support\Money;
use App\Modules\Purchasing\Enums\PurchaseStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseService;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class PurchaseForm extends Component
{
    use SearchesProducts;

    #[Locked]
    public ?int $purchaseId = null;

    #[Url(as: 'supplier')]
    public ?int $supplierId = null;

    public ?int $branchId = null;

    public string $orderDate = '';

    public string $invoiceNumber = '';

    public string $invoiceDate = '';

    public string $discount = '';

    public string $notes = '';

    /** @var list<array{product_id:int, label:string, quantity:string, unit_cost:string, discount:string}> */
    public array $lines = [];

    public string $scan = '';

    public function mount(BranchAccess $access, ?PurchaseOrder $purchase = null): void
    {
        $this->authorize('purchases.create');
        if ($purchase?->exists) {
            $access->authorize($purchase->branch_id);
            abort_unless($purchase->status === PurchaseStatus::Draft, 403, __('Only draft purchase orders can be edited.'));
            $purchase->load('items.product');
            $this->purchaseId = $purchase->id;
            $this->supplierId = $purchase->supplier_id;
            $this->branchId = $purchase->branch_id;
            $this->orderDate = $purchase->order_date->toDateString();
            $this->invoiceNumber = (string) $purchase->supplier_invoice_number;
            $this->invoiceDate = (string) $purchase->supplier_invoice_date?->toDateString();
            $this->notes = (string) $purchase->notes;
            $lineDiscounts = 0;
            foreach ($purchase->items as $item) {
                $this->lines[] = [
                    'product_id' => $item->product_id, 'label' => $item->product->sku.' — '.$item->product->displayName(),
                    'quantity' => (string) $item->quantity, 'unit_cost' => Money::toDecimal($item->unit_cost),
                    'discount' => $item->discount_amount ? Money::toDecimal($item->discount_amount) : '',
                ];
            }
        } else {
            $this->branchId = $access->currentOperational();
            $this->orderDate = today()->toDateString();
        }
    }

    public function addProduct(int $id): void
    {
        $this->push(Product::where('is_active', true)->where('track_stock', true)->findOrFail($id));
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
        foreach ($this->lines as $i => $l) {
            if ($l['product_id'] === $product->id) {
                $this->lines[$i]['quantity'] = (string) ((int) $l['quantity'] + 1);

                return;
            }
        }
        $this->lines[] = [
            'product_id' => $product->id,
            'label' => $product->sku.' — '.$product->displayName(),
            'quantity' => '1',
            'unit_cost' => Money::toDecimal($product->purchase_price),
            'discount' => '',
        ];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function save(PurchaseService $service, bool $confirm = false)
    {
        $this->validate([
            'supplierId' => ['required', 'integer'],
            'branchId' => ['required', 'integer'],
            'orderDate' => ['required', 'date'],
            'invoiceNumber' => ['nullable', 'string', 'max:64'],
            'invoiceDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $lines = [];
        foreach ($this->lines as $i => $l) {
            $lines[] = [
                'product_id' => (int) $l['product_id'],
                'quantity' => (int) $l['quantity'],
                'unit_cost' => $this->money($l['unit_cost'], "lines.$i.unit_cost"),
                'discount' => $this->money($l['discount'], "lines.$i.discount"),
            ];
        }
        $payload = [
            'supplier_id' => (int) $this->supplierId,
            'branch_id' => (int) $this->branchId,
            'order_date' => $this->orderDate,
            'supplier_invoice_number' => $this->invoiceNumber ?: null,
            'supplier_invoice_date' => $this->invoiceDate ?: null,
            'notes' => $this->notes ?: null,
            'discount' => $this->money($this->discount, 'discount'),
            'lines' => $lines,
        ];

        $po = $service->saveDraft($payload, $this->purchaseId ? PurchaseOrder::findOrFail($this->purchaseId) : null);
        if ($confirm) {
            $po = $service->confirm($po);
        }
        session()->flash('success', __('Purchase order :number saved.', ['number' => $po->number]));

        return $this->redirectRoute('purchases.show', $po);
    }

    public function saveAndConfirm(PurchaseService $service)
    {
        return $this->save($service, true);
    }

    private function money(?string $v, string $field): int
    {
        $v = trim((string) $v);
        if ($v === '') {
            return 0;
        }
        if (! Money::isValid($v) || Money::parse($v) < 0) {
            throw ValidationException::withMessages([$field => __('Invalid amount.')]);
        }

        return Money::parse($v);
    }

    public function render(BranchAccess $access, TaxCalculator $tax)
    {
        $preview = ['subtotal' => 0, 'discount_total' => 0, 'tax_total' => 0, 'total' => 0];
        $calcLines = [];
        foreach ($this->lines as $l) {
            if (! Money::isValid($l['unit_cost'] ?: '0') || ! Money::isValid($l['discount'] ?: '0') || (int) $l['quantity'] < 1) {
                continue;
            }
            $calcLines[] = ['quantity' => (int) $l['quantity'], 'unit_price' => max(0, Money::parse($l['unit_cost'] ?: '0')),
                'discount' => min((int) $l['quantity'] * max(0, Money::parse($l['unit_cost'] ?: '0')), max(0, Money::parse($l['discount'] ?: '0'))),
                'tax_rate_bp' => $tax->rateFor(Product::find($l['product_id']))];
        }
        if ($calcLines) {
            $gross = array_sum(array_map(fn ($c) => $c['quantity'] * $c['unit_price'] - $c['discount'], $calcLines));
            $d = Money::isValid($this->discount ?: '0') ? min($gross, max(0, Money::parse($this->discount ?: '0'))) : 0;
            $preview = $tax->calculate($calcLines, $d, false);
        }

        return view('livewire.purchasing.form', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'branches' => $access->allowedBranches(),
            'results' => $this->productResults(),
            'preview' => $preview,
        ])->title($this->purchaseId ? __('Edit purchase order') : __('New purchase order'));
    }
}
