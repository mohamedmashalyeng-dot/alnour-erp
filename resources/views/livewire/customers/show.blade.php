<div>
    <x-page-header :title="$customer->name" :subtitle="$customer->phone">
        <x-slot:actions>
            @if ($canFinance)<a href="{{ route('customers.statement', $customer) }}" target="_blank" class="btn-secondary"><x-icon name="printer" class="size-4" />{{ __('Print statement') }}</a>@endif
            <a href="{{ route('customers.index') }}" class="btn-secondary">{{ __('Back') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('Invoices')" :value="number_format($totals['invoices'])" icon="receipt" />
        <x-stat :label="__('Total purchases')" :value="money($totals['purchases'])" icon="cash" tone="emerald" />
        <x-stat :label="__('Outstanding balance')" :value="money($totals['balance'])" icon="alert" :tone="$totals['balance'] > 0 ? 'rose' : 'sky'" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <div class="card xl:col-span-2">
            <div class="flex gap-2 border-b border-slate-200 p-3">
                <button wire:click="$set('tab', 'invoices')" @class(['btn-primary btn-sm' => $tab === 'invoices', 'btn-secondary btn-sm' => $tab !== 'invoices'])>{{ __('Purchase history') }}</button>
                @if ($canFinance)<button wire:click="$set('tab', 'statement')" @class(['btn-primary btn-sm' => $tab === 'statement', 'btn-secondary btn-sm' => $tab !== 'statement'])>{{ __('Statement') }}</button>@endif
            </div>
            @if ($tab === 'statement' && $statement)
                @include('livewire.partials.statement-table', ['statement' => $statement, 'debitLabel' => __('Debit'), 'creditLabel' => __('Credit')])
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Date') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Total') }}</th><th>{{ __('Balance') }}</th><th>{{ __('Status') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @forelse ($sales as $s)
                            <tr>
                                <td><a href="{{ route('sales.show', $s) }}" class="font-mono text-brand-700" dir="ltr">{{ $s->invoice_number }}</a></td>
                                <td class="text-xs" dir="ltr">{{ $s->sold_at?->format('Y-m-d') }}</td>
                                <td>{{ $s->branch->code }}</td>
                                <td>{{ money($s->total) }}</td>
                                <td>{{ money($s->status->value === 'cancelled' ? 0 : $s->balanceDue()) }}</td>
                                <td><x-status-badge :color="$s->status->color()">{{ $s->status->label() }}</x-status-badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-slate-500">{{ __('No purchases yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $sales->links() }}</div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card card-body space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Phone') }}</span><span dir="ltr">{{ $customer->phone ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Email') }}</span><span dir="ltr">{{ $customer->email ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Address') }}</span><span>{{ $customer->address ?: '—' }}</span></div>
                @if ($customer->notes)<p class="whitespace-pre-line rounded-lg bg-slate-50 p-2 text-xs">{{ $customer->notes }}</p>@endif
            </div>
            @can('payments.record')
                @if ($totals['balance'] > 0)
                    <form wire:submit="collect" class="card card-body space-y-3">
                        <h3 class="font-semibold">{{ __('Collect payment') }}</h3>
                        <p class="text-xs text-slate-500">{{ __('Allocated to the oldest unpaid invoices first.') }}</p>
                        <select wire:model="payment.method" class="form-input">
                            <option value="cash">{{ __('Cash') }}</option><option value="card">{{ __('Card') }}</option>
                            <option value="bank_transfer">{{ __('Bank transfer') }}</option><option value="cheque">{{ __('Cheque') }}</option>
                        </select>
                        <input wire:model="payment.amount" inputmode="decimal" class="form-input" dir="ltr" placeholder="{{ money_input($totals['balance']) }}">
                        @error('payment.amount') <p class="form-error">{{ $message }}</p> @enderror
                        @error('amount') <p class="form-error">{{ $message }}</p> @enderror
                        <input wire:model="payment.reference" class="form-input" dir="ltr" placeholder="{{ __('Reference') }}">
                        <button class="btn-primary" wire:loading.attr="disabled">{{ __('Record payment') }}</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>
</div>
