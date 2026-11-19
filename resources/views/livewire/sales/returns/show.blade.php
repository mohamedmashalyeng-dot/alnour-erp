<div>
    <x-page-header :title="__('Return').' '.$r->number" :subtitle="__('Invoice').' '.$r->sale->invoice_number">
        <x-slot:actions>
            <a href="{{ route('sales.show', $r->sale) }}" class="btn-secondary">{{ __('Original invoice') }}</a>
            <a href="{{ route('returns.index') }}" class="btn-secondary">{{ __('Back') }}</a>
        </x-slot:actions>
    </x-page-header>

    @error('status') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="card">
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Condition') }}</th><th>{{ __('Disposition') }}</th><th>{{ __('Credit') }}</th><th>{{ __('Restocked') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach ($r->items as $item)
                            <tr>
                                <td><div class="font-medium">{{ $item->saleItem->product_name }}</div><div class="font-mono text-xs text-slate-500" dir="ltr">{{ $item->saleItem->sku }}</div></td>
                                <td>{{ $item->quantity }}</td>
                                <td>{{ $item->condition->label() }}</td>
                                <td>
                                    @if ($canApprove)
                                        <select wire:model="dispositions.{{ $item->id }}" class="form-input py-1">
                                            @foreach ($dispositionOptions as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                        </select>
                                    @else
                                        {{ $item->disposition->label() }}
                                    @endif
                                </td>
                                <td>{{ money($item->amount) }}</td>
                                <td>{{ $item->restocked ? __('Yes') : '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($canApprove)
                <div class="card card-body flex flex-wrap items-end gap-3">
                    <button wire:click="complete" wire:confirm="{{ __('Confirm inspection and complete the return?') }}" class="btn-success">{{ __('Confirm inspection & complete') }}</button>
                    <div class="flex grow items-end gap-2">
                        <div class="grow">
                            <label class="form-label">{{ __('Rejection reason') }}</label>
                            <input wire:model="rejectReason" class="form-input">
                            @error('rejectReason') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <button wire:click="reject" class="btn-danger">{{ __('Reject') }}</button>
                    </div>
                </div>
            @endif

            @if ($r->payments->isNotEmpty())
                <div class="card">
                    <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Refunds & credit used') }}</div>
                    <table class="table"><tbody class="divide-y divide-slate-100">
                        @foreach ($r->payments as $p)
                            <tr><td class="font-mono text-xs" dir="ltr">{{ $p->number }}</td><td class="text-xs" dir="ltr">{{ $p->paid_at->format('Y-m-d H:i') }}</td><td>{{ $p->direction === 'out' ? __('Refund') : __('Applied to new sale') }}</td><td>{{ $p->method->label() }}</td><td class="font-semibold">{{ money($p->amount) }}</td></tr>
                        @endforeach
                    </tbody></table>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card card-body space-y-2 text-sm">
                <div class="mb-2"><x-status-badge :color="$r->status->color()" class="text-sm">{{ $r->status->label() }}</x-status-badge> <span class="text-slate-500">{{ $r->type === 'exchange' ? __('Exchange') : __('Return') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Customer') }}</span><span>{{ $r->customer?->name ?? __('Walk-in customer') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Recorded by') }}</span><span>{{ $r->user->name }}</span></div>
                @if ($r->inspector)<div class="flex justify-between"><span class="text-slate-500">{{ __('Inspected by') }}</span><span>{{ $r->inspector->name }}</span></div>@endif
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Reason') }}</span><span>{{ $r->reason }}</span></div>
                <hr class="border-slate-200">
                <div class="flex justify-between font-semibold"><span>{{ __('Credit') }}</span><span>{{ money($r->total) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Refunded') }}</span><span>{{ money($r->refunded_amount) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Used in exchange') }}</span><span>{{ money($r->exchange_credit_used) }}</span></div>
                @if ($r->exchangeSale)<div class="flex justify-between"><span class="text-slate-500">{{ __('Exchange invoice') }}</span><a href="{{ route('sales.show', $r->exchange_sale_id) }}" class="font-mono text-brand-700" dir="ltr">{{ $r->exchangeSale->invoice_number }}</a></div>@endif
                @if ($r->notes)<p class="whitespace-pre-line rounded-lg bg-slate-50 p-2 text-xs">{{ $r->notes }}</p>@endif
            </div>

            @if ($canRefund)
                <form wire:submit="refund" class="card card-body space-y-3">
                    <h3 class="font-semibold">{{ __('Refund to customer') }}</h3>
                    <p class="text-xs text-slate-500">{{ __('Refundable now') }}: {{ money($available) }}. {{ __('For an exchange, enter this return number in the POS instead.') }}</p>
                    <select wire:model="refundMethod" class="form-input">
                        <option value="cash">{{ __('Cash') }}</option><option value="card">{{ __('Card') }}</option><option value="bank_transfer">{{ __('Bank transfer') }}</option>
                    </select>
                    <input wire:model="refundAmount" inputmode="decimal" class="form-input" dir="ltr">
                    @error('refundAmount') <p class="form-error">{{ $message }}</p> @enderror
                    @error('amount') <p class="form-error">{{ $message }}</p> @enderror
                    <input wire:model="refundReference" class="form-input" dir="ltr" placeholder="{{ __('Reference') }}">
                    <button class="btn-primary" wire:loading.attr="disabled">{{ __('Record refund') }}</button>
                </form>
            @endif
        </div>
    </div>
</div>
