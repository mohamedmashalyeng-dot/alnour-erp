<div>
    <x-page-header :title="$purchaseId ? __('Edit purchase order') : __('New purchase order')">
        <x-slot:actions><a href="{{ $purchaseId ? route('purchases.show', $purchaseId) : route('purchases.index') }}" class="btn-secondary">{{ __('Cancel') }}</a></x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-2">
            <div class="card card-body grid gap-3 sm:grid-cols-2">
                <form wire:submit="scanCode">
                    <label class="form-label">{{ __('Scan barcode / SKU') }}</label>
                    <input wire:model="scan" class="form-input font-mono" dir="ltr" placeholder="{{ __('Scan and press Enter') }}">
                    @error('scan') <p class="form-error">{{ $message }}</p> @enderror
                </form>
                <div class="relative">
                    <label class="form-label">{{ __('Search product') }}</label>
                    <input wire:model.live.debounce.250ms="productSearch" class="form-input" placeholder="{{ __('Search by name, SKU or barcode') }}">
                    @if ($results->isNotEmpty())
                        <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                            @foreach ($results as $p)
                                <button type="button" wire:click="addProduct({{ $p->id }})" class="flex w-full items-center justify-between px-3 py-2 text-start text-sm hover:bg-brand-50">
                                    <span>{{ $p->displayName() }}</span><span class="font-mono text-xs text-slate-500" dir="ltr">{{ $p->sku }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            <div class="card table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit cost (excl. tax)') }}</th><th>{{ __('Line discount') }}</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($lines as $i => $line)
                        <tr wire:key="pl-{{ $line['product_id'] }}">
                            <td class="max-w-xs truncate">{{ $line['label'] }}</td>
                            <td><input type="number" min="1" wire:model.live.debounce.400ms="lines.{{ $i }}.quantity" class="form-input w-24 py-1">
                                @error("lines.$i.quantity") <p class="form-error">{{ $message }}</p> @enderror</td>
                            <td><input wire:model.live.debounce.400ms="lines.{{ $i }}.unit_cost" inputmode="decimal" class="form-input w-28 py-1" dir="ltr">
                                @error("lines.$i.unit_cost") <p class="form-error">{{ $message }}</p> @enderror</td>
                            <td><input wire:model.live.debounce.400ms="lines.{{ $i }}.discount" inputmode="decimal" class="form-input w-24 py-1" dir="ltr" placeholder="0.00">
                                @error("lines.$i.discount") <p class="form-error">{{ $message }}</p> @enderror</td>
                            <td class="text-end"><button type="button" wire:click="removeLine({{ $i }})" class="text-rose-600"><x-icon name="trash" class="size-4" /></button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-slate-500">{{ __('Scan or search to add products.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
                @error('lines') <p class="form-error p-3">{{ $message }}</p> @enderror
                @foreach ($errors->get('lines.*') as $messages)@foreach ($messages as $m)<p class="form-error px-3">{{ $m }}</p>@endforeach @endforeach
            </div>
        </div>

        <div class="space-y-4">
            <div class="card card-body space-y-3">
                <div>
                    <label class="form-label">{{ __('Supplier') }} *</label>
                    <select wire:model="supplierId" class="form-input">
                        <option value="">—</option>
                        @foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                    @error('supplierId') <p class="form-error">{{ $message }}</p> @enderror
                    @error('supplier_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Receiving branch') }} *</label>
                    <select wire:model="branchId" class="form-input">
                        @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">{{ __('Order date') }}</label>
                        <input type="date" wire:model="orderDate" class="form-input">
                    </div>
                    <div>
                        <label class="form-label">{{ __('Supplier invoice date') }}</label>
                        <input type="date" wire:model="invoiceDate" class="form-input">
                    </div>
                </div>
                <div>
                    <label class="form-label">{{ __('Supplier invoice number') }}</label>
                    <input wire:model="invoiceNumber" class="form-input" dir="ltr">
                    @error('invoiceNumber') <p class="form-error">{{ $message }}</p> @enderror
                    @error('supplier_invoice_number') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Order discount') }}</label>
                    <input wire:model.live.debounce.400ms="discount" inputmode="decimal" class="form-input" dir="ltr" placeholder="0.00">
                    @error('discount') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Notes') }}</label>
                    <textarea wire:model="notes" rows="2" class="form-input"></textarea>
                </div>
            </div>
            <div class="card card-body space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Subtotal') }}</span><span>{{ money($preview['subtotal']) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Discounts') }}</span><span>− {{ money($preview['discount_total']) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Tax') }}</span><span>{{ money($preview['tax_total']) }}</span></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-lg font-bold"><span>{{ __('Total') }}</span><span>{{ money($preview['total']) }}</span></div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <button wire:click="save" class="btn-secondary" wire:loading.attr="disabled">{{ __('Save draft') }}</button>
                <button wire:click="saveAndConfirm" class="btn-primary" wire:loading.attr="disabled">{{ __('Save & confirm order') }}</button>
            </div>
        </div>
    </div>
</div>
