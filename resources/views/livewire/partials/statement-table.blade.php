<div class="table-wrap">
    <table class="table">
        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Description') }}</th><th>{{ $debitLabel }}</th><th>{{ $creditLabel }}</th><th>{{ __('Balance') }}</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        <tr class="bg-slate-50"><td colspan="5" class="font-medium">{{ __('Opening balance') }}</td><td class="font-semibold">{{ money($statement['opening']) }}</td></tr>
        @forelse ($statement['rows'] as $row)
            <tr>
                <td class="text-xs" dir="ltr">{{ $row['date']->format('Y-m-d H:i') }}</td>
                <td class="font-mono text-xs" dir="ltr">{{ $row['reference'] }}</td>
                <td>{{ $row['description'] }}</td>
                <td>{{ $row['debit'] ? money($row['debit']) : '' }}</td>
                <td>{{ $row['credit'] ? money($row['credit']) : '' }}</td>
                <td class="font-semibold">{{ money($row['balance']) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-center text-slate-500">{{ __('No transactions.') }}</td></tr>
        @endforelse
        <tr class="bg-slate-50"><td colspan="5" class="font-semibold">{{ __('Closing balance') }}</td><td class="font-bold">{{ money($statement['closing']) }}</td></tr>
        </tbody>
    </table>
</div>
