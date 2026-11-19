<div>
    <x-page-header :title="__('Purchase order').' '.$po->number" :subtitle="$po->supplier->name.' · '.$po->branch->displayName()">
        <x-slot:actions>
            <a href="{{ route('purchases.pdf', $po) }}" class="btn-secondary"><x-icon name="printer" class="size-4" />PDF</a>
            @if ($canEdit)<a href="{{ route('purchases.edit', $po) }}" class="btn-secondary">{{ __('Edit') }}</a>@endif
            @if ($canConfirm)<button wire:click="confirm" wire:confirm="{{ __('Confirm this purchase order?') }}" class="btn-primary">{{ __('Confirm order') }}</button>@endif
            @if ($canReceive)<button wire:click="receive" wire:confirm="{{ __('Receive all items into the branch stock? This cannot be undone.') }}" class="btn-success">{{ __('Receive goods') }}</button>@endif
            @if ($canPay)<button wire:click="openPayment" class="btn-primary">{{ __('Record payment') }}</button>@endif
            @if ($canCancel)<button wire:click="$set('showCancel', true)" class="btn-danger">{{ __('Cancel') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    @error('status') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
    @error('amount') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="card table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Unit cost') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Tax') }}</th><th>{{ __('Total') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach ($po->items as $item)
                        <tr>
                            <td><div class="font-medium">{{ $item->product->displayName() }}</div><div class="font-mono text-xs text-slate-500" dir="ltr">{{ $item->product->sku }}</div></td>
                            <td>{{ $item->quantity }}</td>
                            <td>{{ money($item->unit_cost) }}</td>
                            <td>{{ $item->discount_amount ? money($item->discount_amount) : '—' }}</td>
                            <td>{{ money($item->tax_amount) }}</td>
                            <td class="font-semibold">{{ money($item->line_total) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card">
                <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Payments') }}</div>
                <table class="table"><tbody class="divide-y divide-slate-100">
                    @forelse ($payments as $p)
                        <tr><td class="font-mono text-xs" dir="ltr">{{ $p->number }}</td><td class="text-xs" dir="ltr">{{ $p->paid_at->format('Y-m-d') }}</td><td>{{ $p->method->label() }}</td><td dir="ltr">{{ $p->reference }}</td><td class="font-semibold">{{ money($p->allocations->sum('amount')) }}</td><td>{{ $p->user->name }}</td></tr>
                    @empty
                        <tr><td class="text-center text-slate-500">{{ __('No payments recorded.') }}</td></tr>
                    @endforelse
                </tbody></table>
            </div>
        </div>
        <div class="card card-body space-y-2 text-sm">
            <div class="mb-2 flex justify-between">
                <x-status-badge :color="$po->status->color()" class="text-sm">{{ $po->status->label() }}</x-status-badge>
                <x-status-badge :color="$po->payment_status->color()">{{ $po->payment_status->label() }}</x-status-badge>
            </div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Supplier') }}</span><a href="{{ route('suppliers.show', $po->supplier) }}" class="text-brand-700">{{ $po->supplier->name }}</a></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Order date') }}</span><span dir="ltr">{{ $po->order_date->format('Y-m-d') }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Supplier invoice') }}</span><span dir="ltr">{{ $po->supplier_invoice_number ?: '—' }} {{ $po->supplier_invoice_date?->format('Y-m-d') }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Created by') }}</span><span>{{ $po->creator->name }}</span></div>
            @if ($po->received_at)<div class="flex justify-between"><span class="text-slate-500">{{ __('Received') }}</span><span>{{ $po->receiver?->name }} · <span dir="ltr">{{ $po->received_at->format('Y-m-d H:i') }}</span></span></div>@endif
            <hr class="border-slate-200">
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Subtotal') }}</span><span>{{ money($po->subtotal) }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Discounts') }}</span><span>− {{ money($po->discount_total) }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Tax') }}</span><span>{{ money($po->tax_total) }}</span></div>
            <div class="flex justify-between text-lg font-bold"><span>{{ __('Total') }}</span><span>{{ money($po->total) }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Paid') }}</span><span>{{ money($po->paid_amount) }}</span></div>
            <div class="flex justify-between font-semibold {{ $po->balanceDue() > 0 ? 'text-rose-700' : 'text-emerald-700' }}"><span>{{ __('Balance due') }}</span><span>{{ money($po->balanceDue()) }}</span></div>
            @if ($po->cancel_reason)<p class="rounded-lg bg-rose-50 p-2 text-xs text-rose-800">{{ $po->cancel_reason }}</p>@endif
            @if ($po->notes)<p class="whitespace-pre-line rounded-lg bg-slate-50 p-2 text-xs">{{ $po->notes }}</p>@endif
        </div>
    </div>

    <x-modal show="showPayment" :title="__('Record supplier payment')" max-width="max-w-md">
        <form wire:submit="pay" class="space-y-4">
            <select wire:model="payment.method" class="form-input">
                <option value="bank_transfer">{{ __('Bank transfer') }}</option><option value="cash">{{ __('Cash') }}</option>
                <option value="cheque">{{ __('Cheque') }}</option><option value="card">{{ __('Card') }}</option>
            </select>
            <div>
                <input wire:model="payment.amount" inputmode="decimal" class="form-input" dir="ltr">
                @error('payment.amount') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <input wire:model="payment.reference" class="form-input" dir="ltr" placeholder="{{ __('Reference') }}">
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>

    <x-modal show="showCancel" :title="__('Cancel purchase order')" max-width="max-w-md">
        <form wire:submit="cancel" class="space-y-4">
            <input wire:model="cancelReason" class="form-input" placeholder="{{ __('Reason') }}">
            @error('cancelReason') <p class="form-error">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Close') }}</button>
                <button class="btn-danger">{{ __('Cancel purchase order') }}</button>
            </div>
        </form>
    </x-modal>
</div>
