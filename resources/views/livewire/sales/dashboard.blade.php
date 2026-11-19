<div>
    <x-page-header :title="__('Sales dashboard')">
        <x-slot:actions>
            <select wire:model.live="branch" class="form-input w-auto">
                @if ($canAll)<option value="0">{{ __('All branches') }}</option>@endif
                @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
            </select>
            <input type="date" wire:model.live="from" class="form-input w-auto" aria-label="{{ __('From') }}">
            <input type="date" wire:model.live="to" class="form-input w-auto" aria-label="{{ __('To') }}">
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('Net sales today')" :value="money($today['net_sales'])" icon="cash" tone="emerald" />
        <x-stat :label="__('Invoices today')" :value="number_format($today['invoices'])" icon="receipt" />
        <x-stat :label="__('Net sales (period)')" :value="money($summary['net_sales'])" icon="chart" tone="sky" />
        <x-stat :label="__('Outstanding receivables')" :value="money($summary['receivables'])" icon="alert" tone="amber" :href="route('customers.index', ['withBalance' => 1])" />
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <div class="card card-body"><div class="text-sm text-slate-500">{{ __('Gross sales') }}</div><div class="text-lg font-bold">{{ money($summary['gross_sales']) }}</div></div>
        <div class="card card-body"><div class="text-sm text-slate-500">{{ __('Returns') }}</div><div class="text-lg font-bold text-indigo-700">{{ money($summary['returns']) }}</div></div>
        <div class="card card-body"><div class="text-sm text-slate-500">{{ __('Discounts') }}</div><div class="text-lg font-bold">{{ money($summary['discounts']) }}</div></div>
        <div class="card card-body"><div class="text-sm text-slate-500">{{ __('Collected (net)') }}</div><div class="text-lg font-bold text-emerald-700">{{ money($summary['collected']) }}</div></div>
        <div class="card card-body"><div class="text-sm text-slate-500">{{ __('Average invoice') }}</div><div class="text-lg font-bold">{{ money($summary['average']) }}</div></div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <div class="card">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('By payment method') }}</div>
            <table class="table"><tbody class="divide-y divide-slate-100">
                @forelse ($methods as $m => $net)
                    <tr><td>{{ $methodLabels[$m] ?? $m }}</td><td class="text-end font-semibold">{{ money($net) }}</td></tr>
                @empty
                    <tr><td class="text-center text-slate-500">{{ __('No data yet.') }}</td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div class="card">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('By branch') }}</div>
            <table class="table"><tbody class="divide-y divide-slate-100">
                @forelse ($byBranch as $r)
                    <tr><td>{{ app()->getLocale() === 'en' && $r->name_en ? $r->name_en : $r->name }}</td><td>{{ $r->invoices }}</td><td class="text-end font-semibold">{{ money((int) $r->net) }}</td></tr>
                @empty
                    <tr><td class="text-center text-slate-500">{{ __('No data yet.') }}</td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div class="card">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('By cashier') }}</div>
            <table class="table"><tbody class="divide-y divide-slate-100">
                @forelse ($byCashier as $r)
                    <tr><td>{{ $r->name }}</td><td>{{ $r->invoices }}</td><td class="text-end font-semibold">{{ money((int) $r->net) }}</td></tr>
                @empty
                    <tr><td class="text-center text-slate-500">{{ __('No data yet.') }}</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>

    <div class="card mt-6">
        <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Best-selling products') }}</div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Product') }}</th><th>{{ __('SKU') }}</th><th>{{ __('Units sold') }}</th><th>{{ __('Sales') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($top as $t)
                    <tr><td>{{ $t->product_name }}</td><td class="font-mono text-xs" dir="ltr">{{ $t->sku }}</td><td>{{ (int) $t->qty }}</td><td>{{ money((int) $t->amount) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-center text-slate-500">{{ __('No data yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
