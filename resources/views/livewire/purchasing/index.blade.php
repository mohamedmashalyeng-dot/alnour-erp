<div>
    <x-page-header :title="__('Purchase orders')">
        <x-slot:actions>
            @can('purchases.create')<a href="{{ route('purchases.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New purchase order') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>
    <div class="card">
        <div class="flex flex-wrap gap-3 border-b border-slate-200 p-3">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input max-w-xs" placeholder="{{ __('PO number, supplier or invoice') }}">
            <select wire:model.live="status" class="form-input max-w-xs">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
            <select wire:model.live="payment" class="form-input max-w-xs">
                <option value="">{{ __('All payment statuses') }}</option>
                @foreach ($paymentStatuses as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Supplier') }}</th><th>{{ __('Supplier invoice') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Total') }}</th><th>{{ __('Paid') }}</th><th>{{ __('Status') }}</th><th>{{ __('Payment') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($orders as $po)
                    <tr wire:key="po-{{ $po->id }}">
                        <td class="font-mono text-xs" dir="ltr">{{ $po->number }}</td>
                        <td class="text-xs" dir="ltr">{{ $po->order_date->format('Y-m-d') }}</td>
                        <td>{{ $po->supplier->name }}</td>
                        <td dir="ltr">{{ $po->supplier_invoice_number ?: '—' }}</td>
                        <td>{{ $po->branch->code }}</td>
                        <td class="font-semibold">{{ money($po->total) }}</td>
                        <td>{{ money($po->paid_amount) }}</td>
                        <td><x-status-badge :color="$po->status->color()">{{ $po->status->label() }}</x-status-badge></td>
                        <td><x-status-badge :color="$po->payment_status->color()">{{ $po->payment_status->label() }}</x-status-badge></td>
                        <td class="text-end"><a href="{{ route('purchases.show', $po) }}" class="btn-secondary btn-sm">{{ __('View') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="py-10 text-center text-slate-500">{{ __('No purchase orders found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $orders->links() }}</div>
    </div>
</div>
