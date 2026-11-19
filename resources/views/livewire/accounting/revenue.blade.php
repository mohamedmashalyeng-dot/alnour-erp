<div>
    <x-page-header :title="__('Revenue report')" :subtitle="__('Sales revenue (net of returns) and other income from posted entries.')" />
    <div class="no-print mb-3 flex gap-2">
        @foreach (['day' => __('Daily'), 'month' => __('Monthly'), 'year' => __('Annual')] as $g => $label)
            <button wire:click="$set('group', '{{ $g }}')" @class(['btn-primary btn-sm' => $group === $g, 'btn-secondary btn-sm' => $group !== $g])>{{ $label }}</button>
        @endforeach
    </div>
    @include('livewire.accounting.partials.filters')
    <div class="card max-w-3xl table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Period') }}</th><th class="text-end">{{ __('Sales revenue') }}</th><th class="text-end">{{ __('Other income') }}</th><th class="text-end">{{ __('Total') }}</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr><td dir="ltr" class="text-start">{{ $r->period }}</td><td class="text-end">{{ money($r->sales) }}</td><td class="text-end">{{ money($r->other) }}</td><td class="text-end font-semibold">{{ money($r->total) }}</td></tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-slate-500">{{ __('No data yet.') }}</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr class="bg-slate-50 font-bold"><td>{{ __('Total') }}</td><td class="text-end">{{ money($rows->sum('sales')) }}</td><td class="text-end">{{ money($rows->sum('other')) }}</td><td class="text-end">{{ money($rows->sum('total')) }}</td></tr></tfoot>
        </table>
    </div>
</div>
