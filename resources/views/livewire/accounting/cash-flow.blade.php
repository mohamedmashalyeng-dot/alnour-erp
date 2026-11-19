<div>
    <x-page-header :title="__('Cash flow statement')" :subtitle="__('Direct method: movements of cash registers, bank accounts and card clearing.')" />
    @include('livewire.accounting.partials.filters')
    <div class="card max-w-3xl">
        <table class="table">
            <tbody class="divide-y divide-slate-100">
            <tr class="font-semibold"><td>{{ __('Opening cash and bank') }}</td><td class="text-end">{{ money($cf['opening']) }}</td></tr>
            @foreach (['operating' => __('Operating activities'), 'investing' => __('Investing activities'), 'financing' => __('Financing activities')] as $key => $title)
                <tr class="bg-slate-50 font-semibold"><td colspan="2">{{ $title }}</td></tr>
                @forelse ($cf['sections'][$key] as $label => $amount)
                    <tr><td class="ps-6">{{ __($label) }}</td><td class="text-end {{ $amount < 0 ? 'text-rose-700' : '' }}">{{ money($amount) }}</td></tr>
                @empty
                    <tr><td class="ps-6 text-slate-400" colspan="2">—</td></tr>
                @endforelse
                <tr class="font-semibold"><td>{{ __('Net cash from :section', ['section' => $title]) }}</td><td class="text-end">{{ money($cf['totals'][$key]) }}</td></tr>
            @endforeach
            <tr class="font-semibold"><td>{{ __('Net change in cash') }}</td><td class="text-end">{{ money($cf['net_change']) }}</td></tr>
            <tr class="text-base font-bold"><td>{{ __('Closing cash and bank') }}</td><td class="text-end">{{ money($cf['closing']) }}</td></tr>
            </tbody>
        </table>
    </div>
</div>
