<div>
    <x-page-header :title="__('Executive dashboard')" :subtitle="__('Key figures for the selected period and branches.')">
        <x-slot:actions>
            <a href="{{ route('reports.center') }}" class="btn-secondary">{{ __('Report center') }}</a>
            @can('ai.insights')<a href="{{ route('reports.insights') }}" class="btn-primary">{{ __('Business insights') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>
    @include('livewire.accounting.partials.filters')

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('Total sales (incl. VAT)')" :value="money($s['gross_sales'])" icon="cart" />
        <x-stat :label="__('Net revenue')" :value="money($f['total_revenue'])" icon="chart" tone="emerald" />
        <x-stat :label="__('Gross profit')" :value="money($f['gross_profit'])" icon="cash" tone="sky" />
        <x-stat :label="__('Net profit')" :value="money($f['net_profit'])" icon="chart" :tone="$f['net_profit'] >= 0 ? 'emerald' : 'rose'" />
        <x-stat :label="__('Total expenses')" :value="money($f['total_expenses'])" icon="receipt" tone="amber" />
        <x-stat :label="__('Inventory value (cost)')" :value="money($inventoryValue)" icon="archive" />
        <x-stat :label="__('Cash balance')" :value="money($f['cash'] + $f['bank'])" icon="cash" tone="sky" />
        <x-stat :label="__('Customer debts')" :value="money($f['receivables'])" icon="user" tone="amber" />
        <x-stat :label="__('Supplier debts')" :value="money($f['payables'])" icon="truck" tone="rose" />
        <x-stat :label="__('Invoices')" :value="number_format($s['invoices'])" icon="receipt" />
        <x-stat :label="__('Average invoice')" :value="money($s['average'])" icon="receipt" />
        <x-stat :label="__('Returns')" :value="money($s['returns'])" icon="return" tone="rose" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <div class="card">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Best-selling products') }}</div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Product') }}</th><th class="text-end">{{ __('Units') }}</th><th class="text-end">{{ __('Net sales (excl. VAT)') }}</th><th class="text-end">{{ __('Gross profit') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($top as $r)
                    <tr><td>{{ $r['label'] }}</td><td class="text-end">{{ $r['qty'] }}</td><td class="text-end">{{ money($r['revenue']) }}</td><td class="text-end">{{ money($r['profit']) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-center text-slate-500">{{ __('No data yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
        <div class="card">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Branch performance') }}</div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Branch') }}</th><th class="text-end">{{ __('Invoices') }}</th><th class="text-end">{{ __('Net sales (excl. VAT)') }}</th><th class="text-end">{{ __('Gross profit') }}</th><th class="text-end">{{ __('Margin %') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($branchSales as $r)
                    <tr><td>{{ $r['label'] }}</td><td class="text-end">{{ $r['invoices'] }}</td><td class="text-end">{{ money($r['revenue']) }}</td><td class="text-end">{{ money($r['profit']) }}</td>
                        <td class="text-end">{{ $r['margin'] !== null ? number_format($r['margin'] / 100, 1).'%' : '—' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-center text-slate-500">{{ __('No data yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
    </div>
</div>
