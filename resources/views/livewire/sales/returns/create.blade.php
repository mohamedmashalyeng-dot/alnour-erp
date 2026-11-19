<div>
    <x-page-header :title="__('New return')" :subtitle="__('Returns are recorded as separate documents linked to the original invoice.')">
        <x-slot:actions><a href="{{ route('returns.index') }}" class="btn-secondary">{{ __('Back') }}</a></x-slot:actions>
    </x-page-header>

    <form wire:submit="find" class="card card-body mb-6 flex flex-wrap items-end gap-3">
        <div class="grow">
            <label class="form-label">{{ __('Original invoice number') }}</label>
            <input wire:model="invoice" class="form-input font-mono" dir="ltr" placeholder="INV-B01-2026-000001" autofocus>
            @error('invoice') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <button class="btn-primary"><x-icon name="search" class="size-4" />{{ __('Find invoice') }}</button>
    </form>

    @if ($sale)
        <div class="grid gap-6 xl:grid-cols-3">
            <div class="card xl:col-span-2">
                <div class="border-b border-slate-200 px-4 py-3 text-sm">
                    <span class="font-semibold" dir="ltr">{{ $sale->invoice_number }}</span> · {{ $sale->sold_at->format('Y-m-d') }} ·
                    {{ $sale->customer?->name ?? __('Walk-in customer') }} · {{ $sale->branch->displayName() }}
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Sold') }}</th><th>{{ __('Returnable') }}</th><th>{{ __('Paid per unit') }}</th><th>{{ __('Return qty') }}</th><th>{{ __('Condition') }}</th><th>{{ __('Disposition') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach ($sale->items as $item)
                            <tr wire:key="ri-{{ $item->id }}">
                                <td><div class="font-medium">{{ $item->product_name }}</div><div class="font-mono text-xs text-slate-500" dir="ltr">{{ $item->sku }}</div></td>
                                <td>{{ $item->quantity }}</td>
                                <td>{{ $item->returnableQuantity() }}</td>
                                <td>{{ money(intdiv($item->line_total, max(1, $item->quantity))) }}</td>
                                <td>
                                    <input type="number" min="0" max="{{ $item->returnableQuantity() }}" wire:model="lines.{{ $item->id }}.qty" class="form-input w-20 py-1" @disabled($item->returnableQuantity() === 0)>
                                </td>
                                <td>
                                    <select wire:model="lines.{{ $item->id }}.condition" class="form-input py-1">
                                        @foreach ($conditions as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                    </select>
                                </td>
                                <td>
                                    <select wire:model="lines.{{ $item->id }}.disposition" class="form-input py-1">
                                        @foreach ($dispositions as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @error('lines') <p class="form-error p-3">{{ $message }}</p> @enderror
                @foreach ($errors->get('lines.*') as $messages)@foreach ($messages as $m)<p class="form-error px-3">{{ $m }}</p>@endforeach @endforeach
            </div>
            <div class="card card-body space-y-4">
                <div>
                    <label class="form-label">{{ __('Type') }}</label>
                    <select wire:model="type" class="form-input">
                        <option value="return">{{ __('Return (refund)') }}</option>
                        <option value="exchange">{{ __('Exchange (credit for a new sale)') }}</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">{{ __('Reason') }} *</label>
                    <input wire:model="reason" class="form-input" maxlength="255">
                    @error('reason') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Notes') }}</label>
                    <textarea wire:model="notes" rows="2" class="form-input"></textarea>
                </div>
                @can('returns.approve')
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="completeNow" class="mt-0.5 rounded border-slate-300 text-brand-600"> {{ __('I have inspected the items: complete the return now (restock items marked "Return to stock").') }}</label>
                @else
                    <p class="rounded-lg bg-amber-50 p-2 text-xs text-amber-800">{{ __('The return will wait for inspection by an authorized user before stock and credit are applied.') }}</p>
                @endcan
                <button wire:click="submit" type="button" class="btn-primary w-full" wire:loading.attr="disabled">{{ __('Record return') }}</button>
                @error('sale') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>
    @endif
</div>
