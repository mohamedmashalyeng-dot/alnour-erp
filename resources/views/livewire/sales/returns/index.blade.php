<div>
    <x-page-header :title="__('Returns & exchanges')">
        <x-slot:actions><a href="{{ route('returns.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New return') }}</a></x-slot:actions>
    </x-page-header>
    <div class="card">
        <div class="flex flex-wrap gap-3 border-b border-slate-200 p-3">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input max-w-xs" dir="ltr" placeholder="{{ __('Return or invoice number') }}">
            <select wire:model.live="status" class="form-input max-w-xs">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Invoice') }}</th><th>{{ __('Date') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Type') }}</th><th>{{ __('Credit') }}</th><th>{{ __('Refunded') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($returns as $r)
                    <tr wire:key="ret-{{ $r->id }}">
                        <td class="font-mono text-xs" dir="ltr">{{ $r->number }}</td>
                        <td class="font-mono text-xs" dir="ltr">{{ $r->sale->invoice_number }}</td>
                        <td class="text-xs" dir="ltr">{{ $r->created_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $r->branch->code }}</td>
                        <td>{{ $r->customer?->name ?? __('Walk-in customer') }}</td>
                        <td>{{ $r->type === 'exchange' ? __('Exchange') : __('Return') }}</td>
                        <td>{{ money($r->total) }}</td>
                        <td>{{ money($r->refunded_amount + $r->exchange_credit_used) }}</td>
                        <td><x-status-badge :color="$r->status->color()">{{ $r->status->label() }}</x-status-badge></td>
                        <td class="text-end"><a href="{{ route('returns.show', $r) }}" class="btn-secondary btn-sm">{{ __('View') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="py-10 text-center text-slate-500">{{ __('No returns found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $returns->links() }}</div>
    </div>
</div>
