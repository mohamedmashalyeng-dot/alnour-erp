<div>
    <x-page-header :title="__('Journal entries')">
        <x-slot:actions>
            @can('accounting.journal.create')<a href="{{ route('accounting.journal.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('Manual journal entry') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>
    @include('livewire.accounting.partials.filters')
    <div class="card">
        <div class="no-print flex flex-wrap gap-3 border-b border-slate-200 p-3">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input max-w-xs" placeholder="{{ __('Number or description') }}">
            <select wire:model.live="status" class="form-input max-w-xs">
                <option value="">{{ __('All statuses') }}</option>
                <option value="draft">{{ __('journal.draft') }}</option><option value="posted">{{ __('journal.posted') }}</option><option value="reversed">{{ __('journal.reversed') }}</option>
            </select>
            <select wire:model.live="source" class="form-input max-w-xs">
                <option value="">{{ __('All sources') }}</option>
                @foreach (['manual', 'sale', 'sale_return', 'payment', 'purchase_order', 'inventory_transaction', 'stock_transfer', 'expense', 'payroll_run', 'employee_advance', 'treasury_movement', 'cash_shift', 'journal_entry', 'fiscal_year'] as $s)
                    <option value="{{ $s }}">{{ __('source.'.$s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Source') }}</th><th>{{ __('Description') }}</th><th class="text-end">{{ __('Amount') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($entries as $e)
                    <tr wire:key="je-{{ $e->id }}">
                        <td><a href="{{ route('accounting.journal.show', $e) }}" class="font-mono text-xs text-brand-700" dir="ltr">{{ $e->number }}</a></td>
                        <td class="text-xs" dir="ltr">{{ $e->entry_date->format('Y-m-d') }}</td>
                        <td>{{ $e->branch?->code }}</td>
                        <td class="text-xs">{{ __('source.'.($e->source_type ?? 'manual')) }}</td>
                        <td class="max-w-md truncate">{{ $e->memo }}</td>
                        <td class="text-end">{{ money($e->total) }}</td>
                        <td><x-status-badge :color="['draft' => 'amber', 'posted' => 'emerald', 'reversed' => 'slate'][$e->status] ?? 'slate'">{{ __('journal.'.$e->status) }}</x-status-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center text-slate-500">{{ __('No transactions.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $entries->links() }}</div>
    </div>
</div>
