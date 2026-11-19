<?php

namespace App\Modules\Sales\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\Settings;
use App\Modules\Core\Services\TaxCalculator;
use App\Modules\Core\Support\Money;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Optical\Models\Prescription;
use App\Modules\Optical\Services\LoyaltyService;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Sales\Enums\SaleReturnStatus;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cashier screen. The cart lives in component state; prices, tax, discounts limits and stock
 * are all re-validated by SaleService on completion — nothing here is trusted.
 */
#[Title('Point of sale')]
#[Layout('layouts.app')]
class Pos extends Component
{
    /** Idempotency key of the sale being built. Regenerated after each completed sale. */
    #[Locked]
    public string $uuid = '';

    public ?int $branchId = null;

    /** @var array<int, array{product_id:int, name:string, sku:string, price:int, qty:int, discount:string, track:bool}> */
    public array $cart = [];

    public string $scan = '';

    public string $search = '';

    public ?int $customerId = null;

    public string $customerSearch = '';

    public array $newCustomer = ['name' => '', 'phone' => ''];

    public bool $showNewCustomer = false;

    public string $invoiceDiscount = '';

    /** @var list<array{method:string, amount:string, reference:string, tendered:string, sale_return_id:?int}> */
    public array $payments = [];

    public string $returnNumber = '';

    public string $notes = '';

    /** Loyalty points to redeem (validated on the server against the live balance). */
    public string $redeemPoints = '';

    public ?int $prescriptionId = null;

    #[Locked]
    public ?int $lastSaleId = null;

    public function mount(BranchAccess $access): void
    {
        $this->authorize('sales.create');
        $this->branchId = $access->currentOperational();
        abort_unless($this->branchId, 403, __('No branch assigned.'));
        $this->uuid = (string) Str::uuid();
    }

    public function updatedBranchId(BranchAccess $access): void
    {
        $access->authorizeOperational((int) $this->branchId);
    }

    // ---------------------------------------------------------------- cart

    public function scanCode(): void
    {
        $code = trim($this->scan);
        $this->scan = '';
        if ($code === '') {
            return;
        }
        $product = Product::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code)->orWhere('qr_code', $code))
            ->first();
        if (! $product) {
            $this->addError('scan', __('No product found for :code.', ['code' => $code]));

            return;
        }
        $this->addToCart($product);
    }

    public function addProduct(int $id): void
    {
        $this->addToCart(Product::where('is_active', true)->findOrFail($id));
        $this->search = '';
    }

    private function addToCart(Product $product): void
    {
        $this->resetErrorBag();
        $key = (string) $product->id;
        if (isset($this->cart[$key])) {
            $this->cart[$key]['qty']++;
        } else {
            $this->cart[$key] = [
                'product_id' => $product->id,
                'name' => $product->displayName(),
                'sku' => $product->sku,
                'price' => $product->selling_price,
                'qty' => 1,
                'discount' => '',
                'track' => $product->track_stock,
            ];
        }
        $this->dispatch('pos-focus-scan');
    }

    public function increment(string $key): void
    {
        if (isset($this->cart[$key])) {
            $this->cart[$key]['qty']++;
        }
    }

    public function decrement(string $key): void
    {
        if (isset($this->cart[$key])) {
            $this->cart[$key]['qty']--;
            if ($this->cart[$key]['qty'] < 1) {
                unset($this->cart[$key]);
            }
        }
    }

    public function updatedCart($value, $key): void
    {
        [$id, $field] = array_pad(explode('.', (string) $key), 2, null);
        if ($field === 'qty' && isset($this->cart[$id])) {
            $qty = (int) $this->cart[$id]['qty'];
            $this->cart[$id]['qty'] = max(1, min(10000, $qty));
        }
    }

    public function remove(string $key): void
    {
        unset($this->cart[$key]);
    }

    public function clear(): void
    {
        $this->cart = [];
        $this->payments = [];
        $this->invoiceDiscount = '';
        $this->customerId = null;
        $this->redeemPoints = '';
        $this->prescriptionId = null;
        $this->notes = '';
        $this->resetErrorBag();
        $this->uuid = (string) Str::uuid();
    }

    // ---------------------------------------------------------------- customer

    public function selectCustomer(int $id): void
    {
        $this->authorize('customers.view');
        $this->customerId = Customer::where('is_active', true)->findOrFail($id)->id;
        $this->customerSearch = '';
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
        $this->redeemPoints = '';
        $this->prescriptionId = null;
    }

    public function createCustomer(): void
    {
        $this->authorize('customers.manage');
        $data = $this->validate([
            'newCustomer.name' => ['required', 'string', 'max:255'],
            'newCustomer.phone' => ['required', 'string', 'max:32', 'unique:customers,phone'],
        ])['newCustomer'];
        $customer = Customer::create($data + ['branch_id' => $this->branchId, 'created_by' => auth()->id()]);
        $this->customerId = $customer->id;
        $this->newCustomer = ['name' => '', 'phone' => ''];
        $this->showNewCustomer = false;
    }

    // ---------------------------------------------------------------- payments

    public function addPayment(string $method = 'cash'): void
    {
        if (! in_array($method, ['cash', 'card', 'bank_transfer'], true)) {
            return;
        }
        $remaining = $this->totals()['total'] - $this->paidTotal();
        $this->payments[] = [
            'method' => $method,
            'amount' => Money::toDecimal(max(0, $remaining)),
            'reference' => '',
            'tendered' => '',
            'sale_return_id' => null,
        ];
    }

    public function removePayment(int $i): void
    {
        unset($this->payments[$i]);
        $this->payments = array_values($this->payments);
    }

    /** Exchange: use the open credit of a completed return as a (non-cash) payment line. */
    public function applyReturnCredit(BranchAccess $access): void
    {
        $this->authorize('refunds.process');
        $return = SaleReturn::where('number', trim($this->returnNumber))->first();
        if (! $return || $return->status !== SaleReturnStatus::Completed || ! $access->canAccess($return->branch_id)) {
            $this->addError('returnNumber', __('Return not found or not completed.'));

            return;
        }
        $available = $return->total - $return->refunded_amount - $return->exchange_credit_used;
        if ($available <= 0) {
            $this->addError('returnNumber', __('This return has no remaining credit.'));

            return;
        }
        $this->customerId = $return->customer_id;
        $due = $this->totals()['total'] - $this->paidTotal();
        $this->payments[] = [
            'method' => PaymentMethod::StoreCredit->value,
            'amount' => Money::toDecimal(max(0, min($available, $due))),
            'reference' => $return->number,
            'tendered' => '',
            'sale_return_id' => $return->id,
        ];
        $this->returnNumber = '';
    }

    // ---------------------------------------------------------------- complete / hold

    public function complete(SaleService $sales)
    {
        $this->authorize('sales.create');
        if ($this->cart === []) {
            $this->addError('cart', __('The cart is empty.'));

            return null;
        }

        $sale = $sales->complete($this->payload(withPayments: true));

        $this->lastSaleId = $sale->id;
        $this->clear();
        $this->dispatch('toast', message: __('Invoice :number completed.', ['number' => $sale->invoice_number]));
        $this->dispatch('pos-sale-completed', receiptUrl: route('sales.receipt', $sale));

        return null;
    }

    public function hold(SaleService $sales): void
    {
        if ($this->cart === []) {
            return;
        }
        $sales->saveDraft($this->payload(withPayments: false));
        $this->clear();
        $this->dispatch('toast', message: __('Sale held as draft.'));
    }

    public function resume(int $saleId, BranchAccess $access): void
    {
        $this->authorize('sales.create');
        $sale = Sale::with('items.product')->where('status', SaleStatus::Draft->value)->findOrFail($saleId);
        $access->authorize($sale->branch_id);
        $this->clear();
        $this->uuid = $sale->uuid;
        $this->branchId = $sale->branch_id;
        $this->customerId = $sale->customer_id;
        $this->notes = (string) $sale->notes;
        foreach ($sale->items as $item) {
            $this->cart[(string) $item->product_id] = [
                'product_id' => $item->product_id, 'name' => $item->product->displayName(), 'sku' => $item->sku,
                'price' => $item->product->selling_price, 'qty' => $item->quantity,
                'discount' => $item->discount_amount ? Money::toDecimal($item->discount_amount) : '', 'track' => $item->product->track_stock,
            ];
        }
    }

    private function payload(bool $withPayments): array
    {
        $items = [];
        foreach ($this->cart as $key => $line) {
            $items[] = ['product_id' => (int) $line['product_id'], 'quantity' => (int) $line['qty'], 'discount' => $this->parseMoney($line['discount'], "cart.$key.discount")];
        }
        $payments = [];
        if ($withPayments) {
            foreach ($this->payments as $i => $p) {
                $amount = $this->parseMoney($p['amount'], "payments.$i.amount");
                if ($amount <= 0) {
                    continue;
                }
                $payments[] = array_filter([
                    'method' => $p['method'],
                    'amount' => $amount,
                    'reference' => $p['reference'] ?: null,
                    'tendered' => $p['method'] === 'cash' && $p['tendered'] !== '' ? $this->parseMoney($p['tendered'], "payments.$i.tendered") : null,
                    'sale_return_id' => $p['sale_return_id'] ?? null,
                ], fn ($v) => $v !== null);
            }
        }

        return [
            'uuid' => $this->uuid,
            'branch_id' => (int) $this->branchId,
            'customer_id' => $this->customerId,
            'discount' => $this->parseMoney($this->invoiceDiscount, 'invoiceDiscount'),
            'redeem_points' => ctype_digit($this->redeemPoints) ? (int) $this->redeemPoints : null,
            'prescription_id' => $this->prescriptionId,
            'notes' => $this->notes ?: null,
            'items' => $items,
            'payments' => $payments,
        ];
    }

    private function parseMoney(?string $value, string $field): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        if (! Money::isValid($value) || Money::parse($value) < 0) {
            throw ValidationException::withMessages([$field => __('Invalid amount.')]);
        }

        return Money::parse($value);
    }

    /** Preview totals (the server recalculates authoritatively on completion). */
    public function totals(): array
    {
        $calc = app(TaxCalculator::class);
        $lines = [];
        foreach ($this->cart as $line) {
            $lines[] = [
                'quantity' => max(1, (int) $line['qty']),
                'unit_price' => (int) $line['price'],
                'discount' => min((int) $line['qty'] * (int) $line['price'], Money::isValid($line['discount'] ?: '0') ? max(0, Money::parse($line['discount'] ?: '0')) : 0),
                'tax_rate_bp' => $calc->rateFor(Product::find($line['product_id'])),
            ];
        }
        if ($lines === []) {
            return ['subtotal' => 0, 'discount_total' => 0, 'tax_total' => 0, 'total' => 0, 'lines' => []];
        }
        $discount = Money::isValid($this->invoiceDiscount ?: '0') ? max(0, Money::parse($this->invoiceDiscount ?: '0')) : 0;
        $gross = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'] - $l['discount'], $lines));

        // Preview only: the server re-validates the points against the live balance and limits.
        $points = ctype_digit($this->redeemPoints) && $this->customerId ? (int) $this->redeemPoints : 0;
        $discount += $points ? app(LoyaltyService::class)->value($points) : 0;

        return $calc->calculate($lines, min($discount, $gross), $calc->pricesIncludeTax());
    }

    private function paidTotal(): int
    {
        $sum = 0;
        foreach ($this->payments as $p) {
            $sum += Money::isValid($p['amount'] ?: '0') ? max(0, Money::parse($p['amount'] ?: '0')) : 0;
        }

        return $sum;
    }

    public function render(BranchAccess $access, Settings $settings)
    {
        $results = collect();
        if (mb_strlen(trim($this->search)) >= 1) {
            $results = Product::query()->where('is_active', true)->search($this->search)
                ->orderBy('name')->limit(12)->get(['id', 'name', 'name_en', 'sku', 'barcode', 'selling_price', 'track_stock']);
        }
        $stockIds = array_merge($results->pluck('id')->all(), array_column($this->cart, 'product_id'));
        $stock = $stockIds ? Inventory::where('branch_id', $this->branchId)->whereIn('product_id', $stockIds)->get()->keyBy('product_id') : collect();

        $customers = collect();
        if (mb_strlen(trim($this->customerSearch)) >= 2 && auth()->user()->can('customers.view')) {
            $term = '%'.trim($this->customerSearch).'%';
            $customers = Customer::where('is_active', true)
                ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('phone', 'like', $term))
                ->limit(8)->get(['id', 'name', 'phone']);
        }

        $totals = $this->totals();
        $paid = $this->paidTotal();
        $cashTendered = 0;
        $cashAmount = 0;
        foreach ($this->payments as $p) {
            if ($p['method'] === 'cash') {
                $cashAmount += Money::isValid($p['amount'] ?: '0') ? Money::parse($p['amount'] ?: '0') : 0;
                $cashTendered += Money::isValid($p['tendered'] ?: '0') ? Money::parse($p['tendered'] ?: '0') : 0;
            }
        }

        return view('livewire.sales.pos', [
            'branches' => $access->allowedBranches(),
            'results' => $results,
            'stock' => $stock,
            'customers' => $customers,
            'customer' => $this->customerId ? Customer::find($this->customerId) : null,
            'loyaltyEnabled' => app(LoyaltyService::class)->enabled(),
            'customerPrescriptions' => $this->customerId && auth()->user()->can('prescriptions.view')
                ? Prescription::where('customer_id', $this->customerId)->where('status', 'active')
                    ->where('expires_on', '>=', today()->toDateString())->latest('examined_on')->get(['id', 'number', 'examined_on', 'type'])
                : collect(),
            'totals' => $totals,
            'paid' => $paid,
            'remaining' => $totals['total'] - $paid,
            'change' => $cashTendered > $cashAmount ? $cashTendered - $cashAmount : 0,
            'drafts' => Sale::where('status', SaleStatus::Draft->value)->where('branch_id', $this->branchId)->where('cashier_id', auth()->id())->latest()->limit(5)->get(['id', 'uuid', 'total', 'created_at']),
            'lastSale' => $this->lastSaleId ? Sale::find($this->lastSaleId) : null,
            'pricesIncludeTax' => app(TaxCalculator::class)->pricesIncludeTax(),
            'taxEnabled' => app(TaxCalculator::class)->enabled(),
            'needsShift' => $settings->bool('cash_shift_required')
                && ! app(TreasuryService::class)->openShiftFor((int) auth()->id(), (int) $this->branchId),
        ]);
    }
}
