<div>
    <x-page-header :title="$type === 'payables' ? __('Payables aging') : __('Receivables aging')" :subtitle="__('Overdue after :days days.', ['days' => $aging['overdue_days']])" />
    <div class="no-print mb-3 flex flex-wrap gap-2">
        <button wire:click="$set('type', 'receivables')" @class(['btn-primary btn-sm' => $type === 'receivables', 'btn-secondary btn-sm' => $type !== 'receivables'])>{{ __('Customer receivables') }}</button>
        <button wire:click="$set('type', 'payables')" @class(['btn-primary btn-sm' => $type === 'payables', 'btn-secondary btn-sm' => $type !== 'payables'])>{{ __('Supplier payables') }}</button>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="overdueOnly" class="rounded border-slate-300 text-brand-600"> {{ __('Overdue only') }}</label>
    </div>
    @include('livewire.accounting.partials.filters')
    <div class="grid gap-4 sm:grid-cols-5">
        @foreach ($aging['buckets'] as $bucket => $amount)
            <div class="card card-body"><div class="text-sm text-slate-500">{{ __(':bucket days', ['bucket' => $bucket]) }}</div><div class="text-lg font-bold">{{ money($amount) }}</div></div>
        @endforeach
        <div class="card card-body"><div class="text-sm text-slate-500">{{ __('Total') }}</div><div class="text-lg font-bold">{{ money($aging['total']) }}</div></div>
    </div>
    <div class="card mt-6 table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Document') }}</th><th>{{ $type === 'payables' ? __('Supplier') : __('Customer') }}</th><th>{{ __('Date') }}</th><th>{{ __('Age (days)') }}</th><th class="text-end">{{ __('Total') }}</th><th class="text-end">{{ __('Outstanding') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($aging['rows'] as $r)
                <tr>
                    <td><a href="{{ $r->route }}" class="font-mono text-brand-700" dir="ltr">{{ $r->number }}</a></td>
                    <td>{{ $r->party }}</td>
                    <td class="text-xs" dir="ltr">{{ $r->date->format('Y-m-d') }}</td>
                    <td>{{ $r->age }}</td>
                    <td class="text-end">{{ money($r->total) }}</td>
                    <td class="text-end font-semibold">{{ money($r->due) }}</td>
                    <td>@if ($r->overdue)<x-status-badge color="rose">{{ __('Overdue') }}</x-status-badge>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-500">{{ __('Nothing outstanding.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
