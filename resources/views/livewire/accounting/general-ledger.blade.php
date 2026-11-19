<div>
    <x-page-header :title="__('General ledger')" :subtitle="__('Account statement with running balance.')" />
    <div class="no-print card mb-4 p-3">
        <label class="form-label">{{ __('Account') }}</label>
        <select wire:model.live="account" class="form-input max-w-xl">
            <option value="">—</option>
            @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->code }} — {{ $a->displayName() }}</option>@endforeach
        </select>
    </div>
    @include('livewire.accounting.partials.filters')

    @if ($gl)
        <div class="card table-wrap">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ $gl['account']->code }} — {{ $gl['account']->displayName() }}</div>
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Entry') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Description') }}</th><th class="text-end">{{ __('Debit') }}</th><th class="text-end">{{ __('Credit') }}</th><th class="text-end">{{ __('Balance') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                <tr class="bg-slate-50"><td colspan="6" class="font-medium">{{ __('Opening balance') }}</td><td class="text-end font-semibold">{{ money($gl['opening']) }}</td></tr>
                @foreach ($gl['rows'] as $r)
                    <tr>
                        <td class="text-xs" dir="ltr">{{ \Illuminate\Support\Str::limit($r->entry_date, 10, '') }}</td>
                        <td><a href="{{ route('accounting.journal.show', $r->entry_id) }}" class="font-mono text-xs text-brand-700" dir="ltr">{{ $r->number }}</a></td>
                        <td>{{ $r->branch_code }}</td>
                        <td class="max-w-md truncate">{{ $r->description ?: $r->memo }}</td>
                        <td class="text-end">{{ $r->debit ? money((int) $r->debit, false) : '' }}</td>
                        <td class="text-end">{{ $r->credit ? money((int) $r->credit, false) : '' }}</td>
                        <td class="text-end font-semibold">{{ money($r->balance, false) }}</td>
                    </tr>
                @endforeach
                <tr class="bg-slate-50 font-bold"><td colspan="6">{{ __('Closing balance') }}</td><td class="text-end">{{ money($gl['closing']) }}</td></tr>
                </tbody>
            </table>
        </div>
    @else
        <div class="card card-body text-center text-slate-500">{{ __('Choose an account.') }}</div>
    @endif
</div>
