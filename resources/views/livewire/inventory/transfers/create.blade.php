<div>
    <x-page-header :title="__('New transfer')">
        <x-slot:actions><a href="{{ route('transfers.index') }}" class="btn-secondary">{{ __('Cancel') }}</a></x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="card card-body space-y-4 xl:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2">
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
            <div class="table-wrap rounded-lg border border-slate-200">
                <table class="table">
                    <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Available at source') }}</th><th>{{ __('Quantity') }}</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($lines as $i => $line)
                        <tr wire:key="tl-{{ $line['product_id'] }}">
                            <td class="max-w-xs truncate">{{ $line['label'] }}</td>
                            <td>{{ isset($available[$line['product_id']]) ? $available[$line['product_id']]->available() : 0 }}</td>
                            <td>
                                <input type="number" min="1" wire:model="lines.{{ $i }}.quantity" class="form-input w-24 py-1">
                                @error("lines.$i.quantity") <p class="form-error">{{ $message }}</p> @enderror
                            </td>
                            <td class="text-end"><button type="button" wire:click="removeLine({{ $i }})" class="text-rose-600"><x-icon name="trash" class="size-4" /></button></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-slate-500">{{ __('Scan or search to add products.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @error('lines') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div class="card card-body space-y-4">
            <div>
                <label class="form-label">{{ __('From branch') }} *</label>
                <select wire:model.live="fromBranchId" class="form-input">
                    @foreach ($fromBranches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
                </select>
                @error('fromBranchId') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('To branch') }} *</label>
                <select wire:model="toBranchId" class="form-input">
                    <option value="">—</option>
                    @foreach ($toBranches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
                </select>
                @error('toBranchId') <p class="form-error">{{ $message }}</p> @enderror
                @error('to_branch_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Notes') }}</label>
                <textarea wire:model="notes" rows="3" class="form-input"></textarea>
            </div>
            <button wire:click="save" wire:loading.attr="disabled" class="btn-primary w-full">{{ __('Create transfer request') }}</button>
            <p class="text-xs text-slate-500">{{ __('Stock is reserved on approval, leaves the source on dispatch, and arrives on receipt.') }}</p>
        </div>
    </div>
</div>
