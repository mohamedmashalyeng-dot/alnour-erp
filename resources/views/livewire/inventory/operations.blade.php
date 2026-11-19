<div>
    <x-page-header :title="__('Stock operations')" :subtitle="__('Every change is recorded in the stock movement history.')" />

    <div class="mb-4 flex flex-wrap gap-2">
        @can('inventory.receive')
            <button wire:click="$set('mode', 'receive')" @class(['btn-primary' => $mode === 'receive', 'btn-secondary' => $mode !== 'receive'])>{{ __('Receive stock') }}</button>
        @endcan
        @can('inventory.adjust')
            <button wire:click="$set('mode', 'adjust')" @class(['btn-primary' => $mode === 'adjust', 'btn-secondary' => $mode !== 'adjust'])>{{ __('Adjust stock') }}</button>
        @endcan
        @can('inventory.opening-stock')
            <button wire:click="$set('mode', 'opening')" @class(['btn-primary' => $mode === 'opening', 'btn-secondary' => $mode !== 'opening'])>{{ __('Opening stock') }}</button>
        @endcan
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="card card-body space-y-4 xl:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="form-label">{{ __('Branch') }} *</label>
                    <select wire:model.live="branchId" class="form-input">
                        @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
                    </select>
                    @error('branchId') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <form wire:submit="scanCode">
                    <label class="form-label">{{ __('Scan barcode / SKU') }}</label>
                    <input wire:model="scan" class="form-input font-mono" dir="ltr" placeholder="{{ __('Scan and press Enter') }}">
                    @error('scan') <p class="form-error">{{ $message }}</p> @enderror
                </form>
            </div>

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

            <div class="table-wrap rounded-lg border border-slate-200">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('Product') }}</th><th>{{ __('Current') }}</th><th>{{ __('Quantity') }}</th>
                        @if ($mode !== 'adjust')<th>{{ __('Unit cost') }}</th>@endif
                        <th></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($lines as $i => $line)
                        <tr wire:key="line-{{ $line['product_id'] }}">
                            <td class="max-w-xs truncate">{{ $line['label'] }}</td>
                            <td>{{ $currentStock[$line['product_id']]->quantity ?? 0 }}</td>
                            <td>
                                <input type="number" min="1" wire:model="lines.{{ $i }}.quantity" class="form-input w-24 py-1">
                                @error("lines.$i.quantity") <p class="form-error">{{ $message }}</p> @enderror
                            </td>
                            @if ($mode !== 'adjust')
                                <td>
                                    <input wire:model="lines.{{ $i }}.unit_cost" inputmode="decimal" class="form-input w-28 py-1" dir="ltr">
                                    @error("lines.$i.unit_cost") <p class="form-error">{{ $message }}</p> @enderror
                                </td>
                            @endif
                            <td class="text-end"><button type="button" wire:click="removeLine({{ $i }})" class="text-rose-600"><x-icon name="trash" class="size-4" /></button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-slate-500">{{ __('Scan or search to add products.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @error('lines') <p class="form-error">{{ $message }}</p> @enderror
            @error('lines.*') <p class="form-error">{{ $message }}</p> @enderror
            @error('quantity') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div class="card card-body space-y-4">
            @if ($mode === 'adjust')
                <div>
                    <label class="form-label">{{ __('Direction') }}</label>
                    <select wire:model="direction" class="form-input">
                        <option value="decrease">{{ __('Decrease (damage, loss, count correction)') }}</option>
                        <option value="increase">{{ __('Increase (found stock, count correction)') }}</option>
                    </select>
                </div>
                @can('inventory.allow-negative')
                    <label class="flex items-center gap-2 text-sm text-amber-700"><input type="checkbox" wire:model="allowNegative" class="rounded border-slate-300"> {{ __('Allow negative stock (authorized override)') }}</label>
                @endcan
            @endif
            <div>
                <label class="form-label">{{ __('Reason') }} {{ $mode !== 'opening' ? '*' : '' }}</label>
                <input wire:model="reason" class="form-input" maxlength="255">
                @error('reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Notes') }}</label>
                <textarea wire:model="notes" rows="3" class="form-input"></textarea>
            </div>
            <button wire:click="submit" wire:loading.attr="disabled" class="btn-primary w-full" @disabled(empty($lines))>
                {{ match ($mode) { 'opening' => __('Record opening stock'), 'adjust' => __('Apply adjustment'), default => __('Receive stock') } }}
            </button>
        </div>
    </div>
</div>
