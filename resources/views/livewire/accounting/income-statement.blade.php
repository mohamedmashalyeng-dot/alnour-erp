<div>
    <x-page-header :title="__('Income statement')" :subtitle="__('Profit & loss from posted entries (perpetual inventory: cost of goods sold at cost).')" />
    @include('livewire.accounting.partials.filters')
    <div class="no-print mb-4 flex gap-2">
        @foreach (['period' => __('Selected period'), 'monthly' => __('Monthly comparison'), 'annual' => __('Annual comparison')] as $m => $label)
            <button wire:click="$set('mode', '{{ $m }}')" @class(['btn-primary btn-sm' => $mode === $m, 'btn-secondary btn-sm' => $mode !== $m])>{{ $label }}</button>
        @endforeach
    </div>

    @php($acct = fn ($r) => $r->code.' — '.(app()->getLocale() === 'en' && $r->name_en ? $r->name_en : $r->name))

    @if ($mode === 'period')
        <div class="card max-w-3xl">
            <table class="table">
                <tbody class="divide-y divide-slate-100">
                <tr class="bg-slate-50 font-semibold"><td colspan="2">{{ __('Revenue') }}</td></tr>
                @foreach ($pl['revenue'] as $r)<tr><td class="ps-6">{{ $acct($r) }}</td><td class="text-end">{{ money($r->balance) }}</td></tr>@endforeach
                <tr class="font-semibold"><td>{{ __('Total revenue') }}</td><td class="text-end">{{ money($pl['total_revenue']) }}</td></tr>
                <tr class="bg-slate-50 font-semibold"><td colspan="2">{{ __('Cost of goods sold') }}</td></tr>
                @foreach ($pl['cogs'] as $r)<tr><td class="ps-6">{{ $acct($r) }}</td><td class="text-end">{{ money($r->balance) }}</td></tr>@endforeach
                <tr class="font-semibold"><td>{{ __('Gross profit') }}</td><td class="text-end">{{ money($pl['gross_profit']) }}</td></tr>
                <tr class="bg-slate-50 font-semibold"><td colspan="2">{{ __('Operating expenses') }}</td></tr>
                @foreach ($pl['expenses'] as $r)<tr><td class="ps-6">{{ $acct($r) }}</td><td class="text-end">{{ money($r->balance) }}</td></tr>@endforeach
                <tr class="font-semibold"><td>{{ __('Total expenses') }}</td><td class="text-end">{{ money($pl['total_expenses']) }}</td></tr>
                <tr class="text-lg font-bold {{ $pl['net_profit'] < 0 ? 'text-rose-700' : 'text-emerald-700' }}"><td>{{ __('Net profit') }}</td><td class="text-end">{{ money($pl['net_profit']) }}</td></tr>
                </tbody>
            </table>
        </div>
    @elseif ($mode === 'monthly')
        <div class="card table-wrap">
            <table class="table">
                <thead><tr><th>{{ $year }}</th>@foreach ($months as $m => $v)<th class="text-end">{{ $m }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach (['total_revenue' => __('Revenue'), 'total_cogs' => __('Cost of goods sold'), 'gross_profit' => __('Gross profit'), 'total_expenses' => __('Expenses'), 'net_profit' => __('Net profit')] as $k => $label)
                    <tr @class(['font-semibold' => in_array($k, ['gross_profit', 'net_profit'])])><td>{{ $label }}</td>@foreach ($months as $v)<td class="text-end">{{ money($v[$k], false) }}</td>@endforeach</tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        @php($ys = array_keys($years))
        <div class="card max-w-3xl">
            <table class="table">
                <thead><tr><th></th><th class="text-end">{{ $ys[0] }}</th><th class="text-end">{{ $ys[1] }}</th><th class="text-end">{{ __('Change') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach (['total_revenue' => __('Revenue'), 'total_cogs' => __('Cost of goods sold'), 'gross_profit' => __('Gross profit'), 'total_expenses' => __('Expenses'), 'net_profit' => __('Net profit')] as $k => $label)
                    @php($a = $years[$ys[0]][$k])
                    @php($b = $years[$ys[1]][$k])
                    <tr><td>{{ $label }}</td><td class="text-end">{{ money($a) }}</td><td class="text-end">{{ money($b) }}</td>
                        <td class="text-end {{ $b - $a < 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ money($b - $a) }}@if ($a)<span class="text-xs text-slate-500"> ({{ intdiv(($b - $a) * 100, abs($a)) }}%)</span>@endif</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
