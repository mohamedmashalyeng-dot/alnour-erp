<div x-data="{ focusScan() { this.$nextTick(() => this.$refs.scan?.focus()) } }"
     x-init="focusScan()"
     @pos-focus-scan.window="focusScan()"
     @pos-sale-completed.window="focusScan()"
     @keydown.f2.window.prevent="$refs.search.focus()"
     @keydown.f4.window.prevent="$refs.scan.focus()">

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="text-xl font-bold">{{ __('Point of sale') }}</h1>
        <select wire:model.live="branchId" class="form-input max-w-[14rem] py-1.5" aria-label="{{ __('Branch') }}">
            @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
        </select>
        <span class="text-xs text-slate-500">F4: {{ __('scan') }} · F2: {{ __('search') }}</span>
        @if ($lastSale)
            <div class="ms-auto flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-sm text-emerald-800">
                {{ __('Last invoice') }}: <span class="font-mono" dir="ltr">{{ $lastSale->invoice_number }}</span>
                <a href="{{ route('sales.receipt', $lastSale) }}" target="_blank" class="btn-secondary btn-sm"><x-icon name="printer" class="size-4" />{{ __('Receipt') }}</a>
                <a href="{{ route('sales.invoice', $lastSale) }}" target="_blank" class="btn-secondary btn-sm">A4</a>
                <a href="{{ route('sales.pdf', $lastSale) }}" class="btn-secondary btn-sm">PDF</a>
            </div>
        @endif
    </div>

    @if ($needsShift)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ __('No open cash shift: cash payments are blocked until you open a shift.') }}
            @if (Route::has('cash.index'))<a href="{{ route('cash.index') }}" class="font-semibold underline">{{ __('Open shift') }}</a>@endif
        </div>
    @endif

    <div class="grid gap-4 xl:grid-cols-5">
        {{-- Left: scan, search, cart --}}
        <div class="space-y-4 xl:col-span-3">
            <div class="card card-body grid gap-3 sm:grid-cols-2">
                <form wire:submit="scanCode">
                    <label class="form-label">{{ __('Scan barcode / QR / SKU') }}</label>
                    <input x-ref="scan" wire:model="scan" class="form-input font-mono text-lg" dir="ltr" autocomplete="off" placeholder="▌▌▌▌▌">
                    @error('scan') <p class="form-error">{{ $message }}</p> @enderror
                </form>
                <div class="relative">
                    <label class="form-label">{{ __('Search product') }}</label>
                    <input x-ref="search" wire:model.live.debounce.200ms="search" class="form-input" autocomplete="off" placeholder="{{ __('Name, SKU, barcode or model') }}">
                    @if ($results->isNotEmpty())
                        <div class="absolute z-20 mt-1 max-h-80 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                            @foreach ($results as $p)
                                <button type="button" wire:click="addProduct({{ $p->id }})" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm hover:bg-brand-50">
                                    <span><span class="font-medium">{{ $p->displayName() }}</span> <span class="font-mono text-xs text-slate-500" dir="ltr">{{ $p->sku }}</span></span>
                                    <span class="whitespace-nowrap text-xs">
                                        @if ($p->track_stock)<span class="{{ ($stock[$p->id]?->available() ?? 0) > 0 ? 'text-emerald-700' : 'text-rose-600' }}">{{ __('Stock') }}: {{ $stock[$p->id]?->available() ?? 0 }}</span> · @endif
                                        <span class="font-semibold">{{ money($p->selling_price) }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Price') }}</th><th>{{ __('Quantity') }}</th>@can('sales.discount')<th>{{ __('Discount') }}</th>@endcan<th>{{ __('Total') }}</th><th></th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @forelse ($cart as $key => $line)
                            <tr wire:key="cart-{{ $key }}">
                                <td>
                                    <div class="font-medium">{{ $line['name'] }}</div>
                                    <div class="font-mono text-xs text-slate-500" dir="ltr">{{ $line['sku'] }}
                                        @if ($line['track'] && isset($stock[$line['product_id']]) && $stock[$line['product_id']]->available() < $line['qty'])
                                            <span class="text-rose-600">· {{ __('Only :n available', ['n' => $stock[$line['product_id']]->available()]) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ money($line['price']) }}</td>
                                <td>
                                    <div class="flex items-center gap-1">
                                        <button type="button" wire:click="decrement('{{ $key }}')" class="btn-secondary btn-sm">−</button>
                                        <input type="number" min="1" wire:model.live.debounce.400ms="cart.{{ $key }}.qty" class="form-input w-16 py-1 text-center">
                                        <button type="button" wire:click="increment('{{ $key }}')" class="btn-secondary btn-sm">+</button>
                                    </div>
                                </td>
                                @can('sales.discount')
                                    <td><input wire:model.live.debounce.500ms="cart.{{ $key }}.discount" inputmode="decimal" class="form-input w-24 py-1" dir="ltr" placeholder="0.00"></td>
                                @endcan
                                <td class="font-semibold">{{ money($totals['lines'][array_search($key, array_keys($cart))]['line_total'] ?? 0) }}</td>
                                <td><button type="button" wire:click="remove('{{ $key }}')" class="text-rose-600" aria-label="{{ __('Remove') }}"><x-icon name="trash" class="size-4" /></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-12 text-center text-slate-400">{{ __('Scan a barcode or search to add products.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @error('cart') <p class="form-error p-3">{{ $message }}</p> @enderror
                @error('items') <p class="form-error p-3">{{ $message }}</p> @enderror
                @error('quantity') <p class="form-error p-3">{{ $message }}</p> @enderror
            </div>

            @if ($drafts->isNotEmpty())
                <div class="card card-body">
                    <div class="mb-2 text-sm font-semibold">{{ __('Held sales') }}</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($drafts as $d)
                            <button type="button" wire:click="resume({{ $d->id }})" class="btn-secondary btn-sm">{{ $d->created_at->format('H:i') }} · {{ money($d->total) }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Right: customer, totals, payments --}}
        <div class="space-y-4 xl:col-span-2">
            <div class="card card-body space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold">{{ __('Customer') }}</span>
                    @can('customers.manage')<button type="button" wire:click="$toggle('showNewCustomer')" class="text-xs text-brand-700 hover:underline">{{ __('New customer') }}</button>@endcan
                </div>
                @if ($customer)
                    <div class="flex items-center justify-between rounded-lg bg-brand-50 px-3 py-2 text-sm">
                        <span><span class="font-medium">{{ $customer->name }}</span> <span dir="ltr" class="text-slate-500">{{ $customer->phone }}</span></span>
                        <button type="button" wire:click="clearCustomer" class="text-slate-500 hover:text-rose-600"><x-icon name="x" class="size-4" /></button>
                    </div>
                    @if ($loyaltyEnabled && ! $customer->is_walk_in)
                        <div class="mt-2 flex items-center gap-2 text-sm">
                            <span class="text-slate-600">{{ __('Points') }}: <b>{{ number_format($customer->loyalty_points) }}</b></span>
                            @if ($customer->loyalty_points > 0)
                                <input wire:model.live.debounce.500ms="redeemPoints" inputmode="numeric" class="form-input w-28 py-1" dir="ltr" placeholder="{{ __('Redeem') }}">
                            @endif
                        </div>
                        @error('redeem_points') <p class="form-error">{{ $message }}</p> @enderror
                    @endif
                    @if ($customerPrescriptions->isNotEmpty())
                        <select wire:model="prescriptionId" class="form-input mt-2 py-1 text-sm">
                            <option value="">{{ __('Link a prescription (optional)') }}</option>
                            @foreach ($customerPrescriptions as $rx)<option value="{{ $rx->id }}">{{ $rx->number }} — {{ $rx->examined_on->toDateString() }} — {{ __('rx.'.$rx->type) }}</option>@endforeach
                        </select>
                    @endif
                    @error('prescription_id') <p class="form-error">{{ $message }}</p> @enderror
                @else
                    <div class="relative">
                        <input wire:model.live.debounce.300ms="customerSearch" class="form-input" placeholder="{{ __('Walk-in customer — search by name or phone') }}">
                        @if ($customers->isNotEmpty())
                            <div class="absolute z-20 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg">
                                @foreach ($customers as $c)
                                    <button type="button" wire:click="selectCustomer({{ $c->id }})" class="flex w-full justify-between px-3 py-2 text-start text-sm hover:bg-brand-50"><span>{{ $c->name }}</span><span dir="ltr" class="text-slate-500">{{ $c->phone }}</span></button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
                @if ($showNewCustomer)
                    <form wire:submit="createCustomer" class="grid grid-cols-2 gap-2">
                        <input wire:model="newCustomer.name" class="form-input" placeholder="{{ __('Name') }}">
                        <input wire:model="newCustomer.phone" class="form-input" placeholder="{{ __('Phone') }}" dir="ltr">
                        @error('newCustomer.name') <p class="form-error col-span-2">{{ $message }}</p> @enderror
                        @error('newCustomer.phone') <p class="form-error col-span-2">{{ $message }}</p> @enderror
                        <button class="btn-primary btn-sm col-span-2">{{ __('Save customer') }}</button>
                    </form>
                @endif
                @error('customer_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="card card-body space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Subtotal') }}</span><span>{{ money($totals['subtotal']) }}</span></div>
                @can('sales.discount')
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-slate-500">{{ __('Invoice discount') }}</span>
                        <input wire:model.live.debounce.500ms="invoiceDiscount" inputmode="decimal" class="form-input w-28 py-1" dir="ltr" placeholder="0.00">
                    </div>
                @endcan
                @error('discount') <p class="form-error">{{ $message }}</p> @enderror
                @error('invoiceDiscount') <p class="form-error">{{ $message }}</p> @enderror
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Discounts') }}</span><span>− {{ money($totals['discount_total']) }}</span></div>
                @if ($taxEnabled)
                    <div class="flex justify-between"><span class="text-slate-500">{{ $pricesIncludeTax ? __('Tax (included)') : __('Tax') }}</span><span>{{ money($totals['tax_total']) }}</span></div>
                @endif
                <div class="flex justify-between border-t border-slate-200 pt-2 text-xl font-bold"><span>{{ __('Total') }}</span><span>{{ money($totals['total']) }}</span></div>
            </div>

            <div class="card card-body space-y-3">
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="addPayment('cash')" class="btn-secondary btn-sm">+ {{ __('Cash') }}</button>
                    <button type="button" wire:click="addPayment('card')" class="btn-secondary btn-sm">+ {{ __('Card') }}</button>
                    <button type="button" wire:click="addPayment('bank_transfer')" class="btn-secondary btn-sm">+ {{ __('Bank transfer') }}</button>
                </div>
                @foreach ($payments as $i => $p)
                    <div class="rounded-lg border border-slate-200 p-2" wire:key="pay-{{ $i }}">
                        <div class="flex items-center gap-2">
                            <span class="w-24 text-sm font-medium">{{ \App\Modules\Payments\Enums\PaymentMethod::from($p['method'])->label() }}</span>
                            <input wire:model.live.debounce.400ms="payments.{{ $i }}.amount" inputmode="decimal" class="form-input py-1" dir="ltr" @readonly($p['method'] === 'store_credit' && false)>
                            <button type="button" wire:click="removePayment({{ $i }})" class="text-rose-600"><x-icon name="x" class="size-4" /></button>
                        </div>
                        @if ($p['method'] === 'cash')
                            <div class="mt-2 flex items-center gap-2"><span class="w-24 text-xs text-slate-500">{{ __('Tendered') }}</span><input wire:model.live.debounce.400ms="payments.{{ $i }}.tendered" inputmode="decimal" class="form-input py-1" dir="ltr" placeholder="0.00"></div>
                        @elseif ($p['method'] !== 'store_credit')
                            <div class="mt-2 flex items-center gap-2"><span class="w-24 text-xs text-slate-500">{{ __('Reference') }}</span><input wire:model="payments.{{ $i }}.reference" class="form-input py-1" dir="ltr" maxlength="100"></div>
                        @else
                            <div class="mt-1 text-xs text-slate-500">{{ __('Return') }}: <span dir="ltr">{{ $p['reference'] }}</span></div>
                        @endif
                        @error("payments.$i.amount") <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                @can('refunds.process')
                    <form wire:submit="applyReturnCredit" class="flex gap-2">
                        <input wire:model="returnNumber" class="form-input py-1" dir="ltr" placeholder="{{ __('Exchange: return number') }}">
                        <button class="btn-secondary btn-sm whitespace-nowrap">{{ __('Apply credit') }}</button>
                    </form>
                    @error('returnNumber') <p class="form-error">{{ $message }}</p> @enderror
                @endcan
                @error('payments') <p class="form-error">{{ $message }}</p> @enderror
                @error('tendered') <p class="form-error">{{ $message }}</p> @enderror
                @error('amount') <p class="form-error">{{ $message }}</p> @enderror
                @error('uuid') <p class="form-error">{{ $message }}</p> @enderror

                <div class="space-y-1 rounded-lg bg-slate-50 p-3 text-sm">
                    <div class="flex justify-between"><span>{{ __('Paid') }}</span><span>{{ money($paid) }}</span></div>
                    <div class="flex justify-between font-semibold {{ $remaining > 0 ? 'text-rose-700' : 'text-emerald-700' }}"><span>{{ __('Remaining') }}</span><span>{{ money(max(0, $remaining)) }}</span></div>
                    @if ($change > 0)<div class="flex justify-between text-lg font-bold text-brand-700"><span>{{ __('Change') }}</span><span>{{ money($change) }}</span></div>@endif
                </div>

                <textarea wire:model="notes" rows="1" class="form-input" placeholder="{{ __('Notes') }}"></textarea>

                <div class="grid grid-cols-3 gap-2">
                    <button type="button" wire:click="clear" wire:confirm="{{ __('Clear the cart?') }}" class="btn-secondary">{{ __('Clear') }}</button>
                    <button type="button" wire:click="hold" class="btn-secondary" @disabled(empty($cart))>{{ __('Hold') }}</button>
                    <button type="button" wire:click="complete" wire:loading.attr="disabled" wire:target="complete" class="btn-success" @disabled(empty($cart))>
                        <span wire:loading.remove wire:target="complete">{{ __('Complete sale') }}</span>
                        <span wire:loading wire:target="complete">…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
