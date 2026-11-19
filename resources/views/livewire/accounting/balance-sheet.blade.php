<div>
    <x-page-header :title="__('Balance sheet')" />
    @include('livewire.accounting.partials.filters')
    @php($acct = fn ($r) => $r->code.' — '.(app()->getLocale() === 'en' && $r->name_en ? $r->name_en : $r->name))

    @unless ($bs['balanced'])
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{{ __('Warning: the balance sheet does not balance.') }}</div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card">
            <table class="table">
                <tbody class="divide-y divide-slate-100">
                <tr class="bg-slate-50 font-semibold"><td colspan="2">{{ __('Assets') }}</td></tr>
                @foreach ($bs['assets'] as $r)<tr><td>{{ $acct($r) }}</td><td class="text-end">{{ money($r->balance) }}</td></tr>@endforeach
                <tr class="text-base font-bold"><td>{{ __('Total assets') }}</td><td class="text-end">{{ money($bs['total_assets']) }}</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card">
            <table class="table">
                <tbody class="divide-y divide-slate-100">
                <tr class="bg-slate-50 font-semibold"><td colspan="2">{{ __('Liabilities') }}</td></tr>
                @foreach ($bs['liabilities'] as $r)<tr><td>{{ $acct($r) }}</td><td class="text-end">{{ money($r->balance) }}</td></tr>@endforeach
                <tr class="font-semibold"><td>{{ __('Total liabilities') }}</td><td class="text-end">{{ money($bs['total_liabilities']) }}</td></tr>
                <tr class="bg-slate-50 font-semibold"><td colspan="2">{{ __('Equity') }}</td></tr>
                @foreach ($bs['equity'] as $r)<tr><td>{{ $acct($r) }}</td><td class="text-end">{{ money($r->balance) }}</td></tr>@endforeach
                <tr><td>{{ __('Current period earnings (not yet closed)') }}</td><td class="text-end">{{ money($bs['current_earnings']) }}</td></tr>
                <tr class="font-semibold"><td>{{ __('Total equity') }}</td><td class="text-end">{{ money($bs['total_equity']) }}</td></tr>
                <tr class="text-base font-bold"><td>{{ __('Total liabilities and equity') }}</td><td class="text-end">{{ money($bs['total_liabilities'] + $bs['total_equity']) }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
