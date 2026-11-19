<div>
    <x-page-header :title="__('Sales history')">
        <x-slot:actions>
            @can('sales.create')<a href="{{ route('pos') }}" class="btn-primary"><x-icon name="cart" class="size-4" />{{ __('Point of sale') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="grid gap-3 border-b border-slate-200 p-3 sm:grid-cols-3 lg:grid-cols-7">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input lg:col-span-2" placeholder="{{ __('Invoice number, customer name or phone') }}">
            <select wire:model.live="cashier" class="form-input" aria-label="{{ __('Cashier') }}">
                <option value="">{{ __('All cashiers') }}</option>
                @foreach ($cashiers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
            <select wire:model.live="branch" class="form-input">
                @if ($canAll)<option value="0">{{ __('All branches') }}</option>@endif
                @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
            </select>
            <select wire:model.live="status" class="form-input">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
            <input type="date" wire:model.live="from" class="form-input" aria-label="{{ __('From') }}">
            <input type="date" wire:model.live="to" class="form-input" aria-label="{{ __('To') }}">
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Date') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Cashier') }}</th><th>{{ __('Total') }}</th><th>{{ __('Paid') }}</th><th>{{ __('Balance') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($sales as $s)
                    <tr wire:key="sale-{{ $s->id }}">
                        <td class="font-mono" dir="ltr">{{ $s->invoice_number }}</td>
                        <td class="text-xs" dir="ltr">{{ $s->sold_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $s->branch->code }}</td>
                        <td>{{ $s->customer?->name ?? __('Walk-in customer') }}</td>
                        <td>{{ $s->cashier->name }}</td>
                        <td class="font-semibold">{{ money($s->total) }}</td>
                        <td>{{ money($s->paid_amount - $s->refunded_amount) }}</td>
                        <td class="{{ $s->balanceDue() > 0 ? 'font-semibold text-rose-700' : '' }}">{{ money($s->status->value === 'cancelled' ? 0 : $s->balanceDue()) }}</td>
                        <td><x-status-badge :color="$s->status->color()">{{ $s->status->label() }}</x-status-badge></td>
                        <td class="text-end"><a href="{{ route('sales.show', $s) }}" class="btn-secondary btn-sm">{{ __('View') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="py-10 text-center text-slate-500">{{ __('No sales found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $sales->links() }}</div>
    </div>
</div>
