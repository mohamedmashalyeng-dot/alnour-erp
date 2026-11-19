<div>
    <x-page-header :title="__('Trial balance')" />
    @include('livewire.accounting.partials.filters')
    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Account') }}</th><th>{{ __('Type') }}</th><th class="text-end">{{ __('Debit') }}</th><th class="text-end">{{ __('Credit') }}</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($tb['rows'] as $r)
                <tr>
                    <td class="font-mono" dir="ltr"><a href="{{ route('accounting.ledger', ['account' => $r->id, 'to' => $to, 'branch' => $branch]) }}" class="text-brand-700 hover:underline">{{ $r->code }}</a></td>
                    <td>{{ app()->getLocale() === 'en' && $r->name_en ? $r->name_en : $r->name }}</td>
                    <td>{{ \App\Modules\Accounting\Enums\AccountType::from($r->type)->label() }}</td>
                    <td class="text-end">{{ $r->dr ? money($r->dr, false) : '' }}</td>
                    <td class="text-end">{{ $r->cr ? money($r->cr, false) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-8 text-center text-slate-500">{{ __('No transactions.') }}</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr class="bg-slate-50 font-bold {{ $tb['debit'] === $tb['credit'] ? '' : 'text-rose-700' }}"><td colspan="3">{{ __('Total') }}</td><td class="text-end">{{ money($tb['debit']) }}</td><td class="text-end">{{ money($tb['credit']) }}</td></tr></tfoot>
        </table>
    </div>
</div>
