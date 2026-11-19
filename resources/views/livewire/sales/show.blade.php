<div>
    <x-page-header :title="__('Invoice').' '.$sale->invoice_number" :subtitle="$sale->branch->displayName().' · '.$sale->sold_at?->format('Y-m-d H:i')">
        <x-slot:actions>
            <a href="{{ route('sales.receipt', $sale) }}" target="_blank" class="btn-secondary"><x-icon name="printer" class="size-4" />{{ __('Receipt') }}</a>
            <a href="{{ route('sales.invoice', $sale) }}" target="_blank" class="btn-secondary">{{ __('A4 invoice') }}</a>
            <a href="{{ route('sales.pdf', $sale) }}" class="btn-secondary">PDF</a>
            @if ($canPay)<button wire:click="openPayment" class="btn-primary">{{ __('Record payment') }}</button>@endif
            @if ($canReturn)<a href="{{ route('returns.create', ['invoice' => $sale->invoice_number]) }}" class="btn-secondary"><x-icon name="return" class="size-4" />{{ __('Return / exchange') }}</a>@endif
            @if ($canCancel)<button wire:click="$set('showCancel', true)" class="btn-danger">{{ __('Cancel sale') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    @error('status') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
    @error('amount') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="card">
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit price') }}</th><th>{{ __('Discount') }}</th>@if ($sale->tax_total > 0)<th>{{ __('Tax') }}</th>@endif<th>{{ __('Total') }}</th><th>{{ __('Returned') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach ($sale->items as $item)
                            <tr>
                                <td><div class="font-medium">{{ $item->product_name }}</div><div class="font-mono text-xs text-slate-500" dir="ltr">{{ $item->sku }}</div></td>
                                <td>{{ $item->quantity }}</td>
                                <td>{{ money($item->unit_price) }}</td>
                                <td>{{ $item->discount_amount ? money($item->discount_amount) : '—' }}</td>
                                @if ($sale->tax_total > 0)<td>{{ money($item->tax_amount) }}</td>@endif
                                <td class="font-semibold">{{ money($item->line_total) }}</td>
                                <td>{{ $item->returned_quantity ?: '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Payments') }}</div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Method') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Reference') }}</th><th>{{ __('User') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @forelse ($payments as $p)
                            <tr>
                                <td class="font-mono text-xs" dir="ltr">{{ $p->number }}</td>
                                <td class="text-xs" dir="ltr">{{ $p->paid_at->format('Y-m-d H:i') }}</td>
                                <td>@if ($p->direction === 'in')<x-status-badge color="emerald">{{ __('Received') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Refund') }}</x-status-badge>@endif</td>
                                <td>{{ $p->method->label() }}</td>
                                <td class="font-semibold">{{ money($p->allocations->sum('amount')) }}
                                    @if ($p->change_amount)<div class="text-xs font-normal text-slate-500">{{ __('Tendered') }} {{ money($p->tendered_amount) }} · {{ __('Change') }} {{ money($p->change_amount) }}</div>@endif
                                </td>
                                <td dir="ltr">{{ $p->reference ?: '—' }}</td>
                                <td>{{ $p->user->name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-slate-500">{{ __('No payments recorded.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($sale->returns->isNotEmpty())
                <div class="card">
                    <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Returns') }}</div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Units') }}</th><th>{{ __('Credit') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach ($sale->returns as $r)
                                <tr>
                                    <td class="font-mono text-xs" dir="ltr">{{ $r->number }}</td>
                                    <td class="text-xs" dir="ltr">{{ $r->created_at->format('Y-m-d H:i') }}</td>
                                    <td>{{ $r->items->sum('quantity') }}</td>
                                    <td>{{ money($r->total) }}</td>
                                    <td><x-status-badge :color="$r->status->color()">{{ $r->status->label() }}</x-status-badge></td>
                                    <td class="text-end"><a href="{{ route('returns.show', $r) }}" class="btn-secondary btn-sm">{{ __('View') }}</a></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card card-body space-y-2 text-sm">
                <div class="mb-2 flex items-center justify-between">
                    <x-status-badge :color="$sale->status->color()" class="text-sm">{{ $sale->status->label() }}</x-status-badge>
                    <x-status-badge :color="$sale->payment_status->color()">{{ $sale->payment_status->label() }}</x-status-badge>
                </div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Customer') }}</span>
                    <span>@if ($sale->customer)<a href="{{ route('customers.show', $sale->customer) }}" class="text-brand-700 hover:underline">{{ $sale->customer->name }}</a>@else{{ __('Walk-in customer') }}@endif</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Cashier') }}</span><span>{{ $sale->cashier->name }}</span></div>
                <hr class="border-slate-200">
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Subtotal') }}</span><span>{{ money($sale->subtotal) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Discounts') }}</span><span>− {{ money($sale->discount_total) }}</span></div>
                @if ($sale->tax_total > 0)<div class="flex justify-between"><span class="text-slate-500">{{ $sale->prices_include_tax ? __('Tax (included)') : __('Tax') }}</span><span>{{ money($sale->tax_total) }}</span></div>@endif
                <div class="flex justify-between text-lg font-bold"><span>{{ __('Total') }}</span><span>{{ money($sale->total) }}</span></div>
                @if ($sale->returned_amount)<div class="flex justify-between text-indigo-700"><span>{{ __('Returned') }}</span><span>− {{ money($sale->returned_amount) }}</span></div>@endif
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Paid') }}</span><span>{{ money($sale->paid_amount) }}</span></div>
                @if ($sale->refunded_amount)<div class="flex justify-between"><span class="text-slate-500">{{ __('Refunded') }}</span><span>{{ money($sale->refunded_amount) }}</span></div>@endif
                @if ($sale->status->value !== 'cancelled')
                    <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold {{ $sale->balanceDue() > 0 ? 'text-rose-700' : ($sale->balanceDue() < 0 ? 'text-amber-700' : 'text-emerald-700') }}">
                        <span>{{ $sale->balanceDue() < 0 ? __('Credit due to customer') : __('Balance due') }}</span><span>{{ money(abs($sale->balanceDue())) }}</span>
                    </div>
                @endif
                @if ($sale->cancelled_at)
                    <div class="rounded-lg bg-rose-50 p-2 text-xs text-rose-800">{{ __('Cancelled') }} <span dir="ltr">{{ $sale->cancelled_at->format('Y-m-d H:i') }}</span>: {{ $sale->cancel_reason }}</div>
                @endif
                @if ($sale->notes)<p class="whitespace-pre-line rounded-lg bg-slate-50 p-2 text-xs">{{ $sale->notes }}</p>@endif
            </div>
        </div>
    </div>

    <x-modal show="showPayment" :title="__('Record payment')" max-width="max-w-md">
        <form wire:submit="recordPayment" class="space-y-4">
            <div>
                <label class="form-label">{{ __('Method') }}</label>
                <select wire:model="payment.method" class="form-input">
                    <option value="cash">{{ __('Cash') }}</option><option value="card">{{ __('Card') }}</option>
                    <option value="bank_transfer">{{ __('Bank transfer') }}</option><option value="cheque">{{ __('Cheque') }}</option>
                </select>
            </div>
            <div>
                <label class="form-label">{{ __('Amount') }}</label>
                <input wire:model="payment.amount" inputmode="decimal" class="form-input" dir="ltr">
                @error('payment.amount') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Reference') }}</label>
                <input wire:model="payment.reference" class="form-input" dir="ltr">
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>

    <x-modal show="showCancel" :title="__('Cancel sale')" max-width="max-w-md">
        <form wire:submit="cancelSale" class="space-y-4">
            <p class="text-sm text-slate-600">{{ __('Stock will be returned to the branch and any amount paid will be refunded.') }}</p>
            <div>
                <label class="form-label">{{ __('Reason') }} *</label>
                <input wire:model="cancelReason" class="form-input" maxlength="255">
                @error('cancelReason') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            @if ($sale->paid_amount - $sale->refunded_amount > 0)
                <div>
                    <label class="form-label">{{ __('Refund method') }}</label>
                    <select wire:model="refundMethod" class="form-input">
                        <option value="cash">{{ __('Cash') }}</option><option value="card">{{ __('Card') }}</option><option value="bank_transfer">{{ __('Bank transfer') }}</option>
                    </select>
                </div>
            @endif
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Close') }}</button>
                <button type="submit" class="btn-danger">{{ __('Cancel sale') }}</button>
            </div>
        </form>
    </x-modal>
</div>
