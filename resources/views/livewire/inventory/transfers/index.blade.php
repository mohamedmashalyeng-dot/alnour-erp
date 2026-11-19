<div>
    <x-page-header :title="__('Stock transfers')">
        <x-slot:actions>
            @can('transfers.create')
                <a href="{{ route('transfers.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New transfer') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="flex flex-wrap gap-3 border-b border-slate-200 p-3">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input max-w-xs" placeholder="{{ __('Transfer number') }}" dir="ltr">
            <select wire:model.live="status" class="form-input max-w-xs">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Number') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Units') }}</th><th>{{ __('Status') }}</th><th>{{ __('Created by') }}</th><th>{{ __('Date') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($transfers as $t)
                    <tr wire:key="t-{{ $t->id }}">
                        <td class="font-mono" dir="ltr">{{ $t->number }}</td>
                        <td>{{ $t->fromBranch->displayName() }}</td>
                        <td>{{ $t->toBranch->displayName() }}</td>
                        <td>{{ (int) $t->units }}</td>
                        <td><x-status-badge :color="$t->status->color()">{{ $t->status->label() }}</x-status-badge></td>
                        <td>{{ $t->creator->name }}</td>
                        <td class="text-xs" dir="ltr">{{ $t->created_at->format('Y-m-d H:i') }}</td>
                        <td class="text-end"><a href="{{ route('transfers.show', $t) }}" class="btn-secondary btn-sm">{{ __('View') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-10 text-center text-slate-500">{{ __('No transfers found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $transfers->links() }}</div>
    </div>
</div>
