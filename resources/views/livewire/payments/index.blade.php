<div>
    <x-page-header :title="__('Payments')" :subtitle="__('Register of all money received and paid.')" />
    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('Received')" :value="money((int) $totals->received)" icon="cash" tone="emerald" />
        <x-stat :label="__('Paid / refunded')" :value="money((int) $totals->paid)" icon="cash" tone="rose" />
        <x-stat :label="__('Net')" :value="money((int) $totals->received - (int) $totals->paid)" icon="chart" />
    </div>
    <div class="card">
        <div class="grid gap-3 border-b border-slate-200 p-3 sm:grid-cols-3 lg:grid-cols-6">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input" dir="ltr" placeholder="{{ __('Number or reference') }}">
            <select wire:model.live="party" class="form-input"><option value="">{{ __('Customers & suppliers') }}</option><option value="customer">{{ __('Customers') }}</option><option value="supplier">{{ __('Suppliers') }}</option></select>
            <select wire:model.live="direction" class="form-input"><option value="">{{ __('In & out') }}</option><option value="in">{{ __('Received') }}</option><option value="out">{{ __('Paid / refunded') }}</option></select>
            <select wire:model.live="method" class="form-input"><option value="">{{ __('All methods') }}</option>@foreach ($methods as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
            <input type="date" wire:model.live="from" class="form-input" aria-label="{{ __('From') }}">
            <input type="date" wire:model.live="to" class="form-input" aria-label="{{ __('To') }}">
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Party') }}</th><th>{{ __('Type') }}</th><th>{{ __('Method') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Reference') }}</th><th>{{ __('User') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($payments as $p)
                    <tr wire:key="pay-{{ $p->id }}">
                        <td class="font-mono text-xs" dir="ltr">{{ $p->number }}</td>
                        <td class="text-xs" dir="ltr">{{ $p->paid_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $p->branch->code }}</td>
                        <td>{{ $p->party_type === 'supplier' ? $p->supplier?->name : ($p->customer?->name ?? __('Walk-in customer')) }}</td>
                        <td>@if ($p->direction === 'in')<x-status-badge color="emerald">{{ __('Received') }}</x-status-badge>@else<x-status-badge color="rose">{{ $p->party_type === 'supplier' ? __('Paid') : __('Refund') }}</x-status-badge>@endif</td>
                        <td>{{ $p->method->label() }}</td>
                        <td class="font-semibold">{{ money($p->amount) }}</td>
                        <td dir="ltr">{{ $p->reference ?: '—' }}</td>
                        <td>{{ $p->user->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-10 text-center text-slate-500">{{ __('No payments found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $payments->links() }}</div>
    </div>
</div>
