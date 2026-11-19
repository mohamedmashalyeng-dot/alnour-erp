<div>
    <x-page-header :title="__('Journal entry').' '.$e->number" :subtitle="$e->memo">
        <x-slot:actions>
            @if ($e->status === 'draft')
                @can('accounting.journal.post')<button wire:click="post" wire:confirm="{{ __('Post this entry? Posted entries cannot be edited.') }}" class="btn-success">{{ __('Post') }}</button>@endcan
                @can('accounting.journal.create')<button wire:click="deleteDraft" wire:confirm="{{ __('Are you sure?') }}" class="btn-danger">{{ __('Delete draft') }}</button>@endcan
            @elseif ($e->status === 'posted')
                @can('accounting.journal.post')<button wire:click="$set('showReverse', true)" class="btn-secondary">{{ __('Reverse') }}</button>@endcan
            @endif
            <button onclick="window.print()" class="btn-secondary no-print"><x-icon name="printer" class="size-4" />{{ __('Print') }}</button>
            <a href="{{ route('accounting.journal') }}" class="btn-secondary">{{ __('Back') }}</a>
        </x-slot:actions>
    </x-page-header>
    @error('entry') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
    @error('entry_date') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
    @error('lines') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="card table-wrap xl:col-span-2">
            <table class="table">
                <thead><tr><th>{{ __('Account') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Description') }}</th><th class="text-end">{{ __('Debit') }}</th><th class="text-end">{{ __('Credit') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($e->lines as $l)
                    <tr>
                        <td><span class="font-mono text-xs" dir="ltr">{{ $l->account->code }}</span> {{ $l->account->displayName() }}
                            @if ($l->party_type)<div class="text-xs text-slate-500">{{ __('party.'.$l->party_type) }} #{{ $l->party_id }}</div>@endif</td>
                        <td>{{ $l->branch->code }}</td>
                        <td class="max-w-xs truncate">{{ $l->description }}</td>
                        <td class="text-end">{{ $l->debit ? money($l->debit, false) : '' }}</td>
                        <td class="text-end">{{ $l->credit ? money($l->credit, false) : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr class="bg-slate-50 font-bold"><td colspan="3">{{ __('Total') }}</td><td class="text-end">{{ money($e->lines->sum('debit')) }}</td><td class="text-end">{{ money($e->lines->sum('credit')) }}</td></tr></tfoot>
            </table>
        </div>
        <div class="card card-body space-y-2 text-sm">
            <div><x-status-badge :color="['draft' => 'amber', 'posted' => 'emerald', 'reversed' => 'slate'][$e->status] ?? 'slate'">{{ __('journal.'.$e->status) }}</x-status-badge></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Date') }}</span><span dir="ltr">{{ $e->entry_date->format('Y-m-d') }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Source') }}</span><span>{{ __('source.'.($e->source_type ?? 'manual')) }} @if ($e->source_id)#{{ $e->source_id }}@endif</span></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('Created by') }}</span><span>{{ $e->creator?->name ?? '—' }}</span></div>
            @if ($e->posted_at)<div class="flex justify-between"><span class="text-slate-500">{{ __('Posted') }}</span><span>{{ $e->poster?->name }} · <span dir="ltr">{{ $e->posted_at->format('Y-m-d H:i') }}</span></span></div>@endif
            @if ($e->reversalOf)<div class="flex justify-between"><span class="text-slate-500">{{ __('Reverses') }}</span><a href="{{ route('accounting.journal.show', $e->reversal_of_id) }}" class="font-mono text-brand-700" dir="ltr">{{ $e->reversalOf->number }}</a></div>@endif
            @if ($e->reversedBy)<div class="flex justify-between"><span class="text-slate-500">{{ __('Reversed by') }}</span><a href="{{ route('accounting.journal.show', $e->reversed_by_id) }}" class="font-mono text-brand-700" dir="ltr">{{ $e->reversedBy->number }}</a></div>@endif
        </div>
    </div>

    <x-modal show="showReverse" :title="__('Reverse entry')" max-width="max-w-md">
        <form wire:submit="reverse" class="space-y-4">
            <p class="text-sm text-slate-600">{{ __('A mirror-image entry will be posted today. The original stays in the ledger.') }}</p>
            <input wire:model="reason" class="form-input" placeholder="{{ __('Reason') }}">
            @error('reason') <p class="form-error">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Close') }}</button>
                <button class="btn-danger">{{ __('Reverse') }}</button>
            </div>
        </form>
    </x-modal>
</div>
