<div>
    <x-page-header :title="__('Accounting periods')" :subtitle="__('Closed periods reject any new posting. The fiscal year follows the calendar year.')">
        <x-slot:actions>
            <button wire:click="$set('year', {{ $year - 1 }})" class="btn-secondary">‹</button>
            <span class="font-semibold" dir="ltr">{{ $year }}</span>
            <button wire:click="$set('year', {{ $year + 1 }})" class="btn-secondary">›</button>
        </x-slot:actions>
    </x-page-header>
    @error('period') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Period') }}</th><th>{{ __('Journal entries') }}</th><th>{{ __('Status') }}</th><th>{{ __('Closed by') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($months as $m)
                <tr wire:key="p-{{ $year }}-{{ $m->month }}">
                    <td class="font-medium">{{ $m->label }}</td>
                    <td>{{ $m->entries }}</td>
                    <td>@if ($m->status === 'closed')<x-status-badge color="slate">{{ __('Closed') }}</x-status-badge>@else<x-status-badge color="emerald">{{ __('Open') }}</x-status-badge>@endif</td>
                    <td class="text-xs">{{ $m->closer }} @if ($m->closed_at)<span dir="ltr">{{ $m->closed_at->format('Y-m-d') }}</span>@endif</td>
                    <td class="text-end">
                        @if ($m->status === 'open' && ! $m->future)
                            @can('accounting.close-period')<button wire:click="close({{ $m->month }})" wire:confirm="{{ __('Close this period? No further postings will be allowed.') }}" class="btn-secondary btn-sm">{{ __('Close period') }}</button>@endcan
                        @elseif ($m->status === 'closed' && ! $yearClosed)
                            @can('accounting.reopen-period')<button wire:click="reopen({{ $m->month }})" wire:confirm="{{ __('Reopen this period?') }}" class="btn-secondary btn-sm">{{ __('Reopen') }}</button>@endcan
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4">
            <p class="text-sm text-slate-500">{{ __('Year-end closing moves the profit or loss of the year into retained earnings (per branch) and locks all months.') }}</p>
            @if ($yearClosed)
                @can('accounting.reopen-period')<button wire:click="reopenYear" wire:confirm="{{ __('Reopen the fiscal year? The closing entry will be reversed.') }}" class="btn-secondary">{{ __('Reopen fiscal year') }}</button>@endcan
            @elseif ($year < (int) today()->format('Y'))
                @can('accounting.close-period')<button wire:click="closeYear" wire:confirm="{{ __('Close the fiscal year :year?', ['year' => $year]) }}" class="btn-danger">{{ __('Close fiscal year') }}</button>@endcan
            @endif
        </div>
    </div>
</div>
