<div>
    <x-page-header :title="__('Financial dashboard')" :subtitle="__('All figures come from posted journal entries.')" />
    @include('livewire.accounting.partials.filters')

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('Total revenue')" :value="money($d['total_revenue'])" icon="chart" tone="emerald" :href="route('accounting.income-statement', ['from' => $from, 'to' => $to, 'branch' => $branch])" />
        <x-stat :label="__('Gross profit')" :value="money($d['gross_profit'])" icon="cash" tone="sky" />
        <x-stat :label="__('Total expenses')" :value="money($d['total_expenses'])" icon="receipt" tone="amber" />
        <x-stat :label="__('Net profit')" :value="money($d['net_profit'])" icon="chart" :tone="$d['net_profit'] >= 0 ? 'emerald' : 'rose'" />
    </div>
    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('Cash balances')" :value="money($d['cash'])" icon="cash" :href="Route::has('cash.index') ? route('cash.index') : null" />
        <x-stat :label="__('Bank balances')" :value="money($d['bank'] + $d['card_clearing'])" icon="building" />
        <x-stat :label="__('Customer receivables')" :value="money($d['receivables'])" icon="user" tone="amber" :href="route('accounting.aging', ['type' => 'receivables'])" />
        <x-stat :label="__('Supplier payables')" :value="money($d['payables'])" icon="truck" tone="rose" :href="route('accounting.aging', ['type' => 'payables'])" />
    </div>
    <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <div class="card card-body"><div class="text-sm text-slate-500">{{ __('Cost of goods sold') }}</div><div class="text-lg font-bold">{{ money($d['total_cogs']) }}</div></div>
        <div class="card card-body"><div class="text-sm text-slate-500">{{ __('Card payments clearing') }}</div><div class="text-lg font-bold">{{ money($d['card_clearing']) }}</div></div>
        <div class="card card-body"><div class="text-sm text-slate-500">{{ __('VAT payable (net)') }}</div><div class="text-lg font-bold">{{ money($d['vat_payable']) }}</div></div>
    </div>

    <div class="card mt-6">
        <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Branch performance') }}</div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Branch') }}</th><th>{{ __('Revenue') }}</th><th>{{ __('Cost of goods sold') }}</th><th>{{ __('Gross profit') }}</th><th>{{ __('Expenses') }}</th><th>{{ __('Net profit') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($d['branches'] as $r)
                    <tr>
                        <td class="font-medium">{{ app()->getLocale() === 'en' && $r->branch->name_en ? $r->branch->name_en : $r->branch->name }}</td>
                        <td>{{ money($r->revenue) }}</td><td>{{ money($r->cogs) }}</td><td>{{ money($r->gross) }}</td><td>{{ money($r->expenses) }}</td>
                        <td class="font-semibold {{ $r->net < 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ money($r->net) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
