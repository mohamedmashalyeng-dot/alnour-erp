<div>
    <x-page-header :title="$supplier->name" :subtitle="$supplier->company_name">
        <x-slot:actions>
            @if ($canFinance)<a href="{{ route('suppliers.statement', $supplier) }}" target="_blank" class="btn-secondary"><x-icon name="printer" class="size-4" />{{ __('Print statement') }}</a>@endif
            @can('purchases.create')<a href="{{ route('purchases.create', ['supplier' => $supplier->id]) }}" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New purchase order') }}</a>@endcan
            <a href="{{ route('suppliers.index') }}" class="btn-secondary">{{ __('Back') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('Total purchases')" :value="money($totals['purchases'])" icon="clipboard" />
        <x-stat :label="__('Total payments')" :value="money($totals['payments'])" icon="cash" tone="emerald" />
        <x-stat :label="__('Outstanding balance')" :value="money($totals['balance'])" icon="alert" :tone="$totals['balance'] > 0 ? 'rose' : 'sky'" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <div class="card xl:col-span-2">
            <div class="flex gap-2 border-b border-slate-200 p-3">
                @if ($canFinance)<button wire:click="$set('tab', 'statement')" @class(['btn-primary btn-sm' => $tab === 'statement', 'btn-secondary btn-sm' => $tab !== 'statement'])>{{ __('Statement') }}</button>@endif
                <button wire:click="$set('tab', 'orders')" @class(['btn-primary btn-sm' => $tab === 'orders' || ! $canFinance, 'btn-secondary btn-sm' => $tab !== 'orders' && $canFinance])>{{ __('Purchase history') }}</button>
            </div>
            @if ($statement)
                @include('livewire.partials.statement-table', ['statement' => $statement, 'debitLabel' => __('Paid'), 'creditLabel' => __('Purchases')])
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Total') }}</th><th>{{ __('Paid') }}</th><th>{{ __('Status') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @forelse ($orders as $po)
                            <tr>
                                <td><a href="{{ route('purchases.show', $po) }}" class="font-mono text-brand-700" dir="ltr">{{ $po->number }}</a></td>
                                <td class="text-xs" dir="ltr">{{ $po->order_date->format('Y-m-d') }}</td>
                                <td>{{ $po->branch->code }}</td>
                                <td>{{ money($po->total) }}</td>
                                <td>{{ money($po->paid_amount) }}</td>
                                <td><x-status-badge :color="$po->status->color()">{{ $po->status->label() }}</x-status-badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-slate-500">{{ __('No purchase orders yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        <div class="space-y-6">
            <div class="card card-body space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Phone') }}</span><span dir="ltr">{{ $supplier->phone ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Email') }}</span><span dir="ltr">{{ $supplier->email ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Address') }}</span><span>{{ $supplier->address ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('Tax number') }}</span><span dir="ltr">{{ $supplier->tax_number ?: '—' }}</span></div>
                @if ($supplier->notes)<p class="whitespace-pre-line rounded-lg bg-slate-50 p-2 text-xs">{{ $supplier->notes }}</p>@endif
            </div>
            @can('supplier-payments.record')
                <form wire:submit="pay" class="card card-body space-y-3">
                    <h3 class="font-semibold">{{ __('Record supplier payment') }}</h3>
                    <p class="text-xs text-slate-500">{{ __('Allocated to the oldest open purchase orders first.') }}</p>
                    <select wire:model="payment.branch_id" class="form-input" aria-label="{{ __('Branch') }}">
                        @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
                    </select>
                    <select wire:model="payment.method" class="form-input">
                        <option value="bank_transfer">{{ __('Bank transfer') }}</option><option value="cash">{{ __('Cash') }}</option>
                        <option value="cheque">{{ __('Cheque') }}</option><option value="card">{{ __('Card') }}</option>
                    </select>
                    <input wire:model="payment.amount" inputmode="decimal" class="form-input" dir="ltr" placeholder="0.00">
                    @error('payment.amount') <p class="form-error">{{ $message }}</p> @enderror
                    @error('amount') <p class="form-error">{{ $message }}</p> @enderror
                    <input wire:model="payment.reference" class="form-input" dir="ltr" placeholder="{{ __('Reference') }}">
                    <button class="btn-primary" wire:loading.attr="disabled">{{ __('Record payment') }}</button>
                </form>
            @endcan
        </div>
    </div>
</div>
