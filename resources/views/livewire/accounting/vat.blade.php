<div>
    <x-page-header :title="__('VAT report')" :subtitle="__('Rates and tax accounts are configured in Settings and the chart of accounts.')" />
    @include('livewire.accounting.partials.filters')
    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('Output VAT (sales)')" :value="money($vat['output'])" icon="receipt" />
        <x-stat :label="__('Input VAT (purchases & expenses)')" :value="money($vat['input'])" icon="clipboard" tone="sky" />
        <x-stat :label="__('Net VAT payable')" :value="money($vat['net'])" icon="cash" :tone="$vat['net'] >= 0 ? 'amber' : 'emerald'" />
    </div>
    <div class="card mt-6 table-wrap">
        <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Tax amounts per invoice') }}</div>
        <table class="table">
            <thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Date') }}</th><th class="text-end">{{ __('Total') }}</th><th class="text-end">{{ __('Tax') }}</th><th class="text-end">{{ __('Returned') }}</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($vat['invoices'] as $s)
                <tr><td><a href="{{ route('sales.show', $s) }}" class="font-mono text-brand-700" dir="ltr">{{ $s->invoice_number }}</a></td><td class="text-xs" dir="ltr">{{ $s->sold_at->format('Y-m-d') }}</td>
                    <td class="text-end">{{ money($s->total) }}</td><td class="text-end">{{ money($s->tax_total) }}</td><td class="text-end">{{ $s->returned_amount ? money($s->returned_amount) : '' }}</td></tr>
            @empty
                <tr><td colspan="5" class="py-6 text-center text-slate-500">{{ __('No taxable invoices in this period.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
