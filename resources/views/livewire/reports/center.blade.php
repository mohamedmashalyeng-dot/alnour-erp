<div>
    <x-page-header :title="__('Report center')">
        <x-slot:actions>
            @foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $fmt => $label)
                <a href="{{ route('reports.export', $exportParams + ['format' => $fmt]) }}" class="btn-secondary">{{ $label }}</a>
            @endforeach
        </x-slot:actions>
    </x-page-header>

    <div class="no-print card mb-4 grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
            <label class="form-label">{{ __('Report') }}</label>
            <select wire:model.live="report" class="form-input">
                @foreach ($reports as $k => $label)<option value="{{ $k }}">{{ __($label) }}</option>@endforeach
            </select>
        </div>
        @if ($report === 'sales_period')
            <div>
                <label class="form-label">{{ __('Group by') }}</label>
                <select wire:model.live="group" class="form-input">
                    <option value="day">{{ __('Daily') }}</option><option value="week">{{ __('Weekly') }}</option>
                    <option value="month">{{ __('Monthly') }}</option><option value="year">{{ __('Annual') }}</option>
                </select>
            </div>
        @endif
        @if ($comparable)
            <label class="flex items-center gap-2 self-end text-sm"><input type="checkbox" wire:model.live="compare" class="rounded border-slate-300 text-brand-600"> {{ __('Compare with another period') }}</label>
        @endif
        @if ($compare && $comparable)
            <div><label class="form-label">{{ __('Period A from') }}</label><input type="date" wire:model.live="fromB" class="form-input"></div>
            <div><label class="form-label">{{ __('Period A to') }}</label><input type="date" wire:model.live="toB" class="form-input"></div>
            <p class="self-end text-xs text-slate-500 lg:col-span-2">{{ __('Period B is the main date range below.') }}</p>
        @endif
    </div>
    @include('livewire.accounting.partials.filters')

    <div class="card table-wrap">
        <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __($data['title']) }}</div>
        <table class="table">
            <thead><tr>@foreach ($data['columns'] as $col)<th @class(['text-end' => $col['type'] !== 'text'])>{{ __($col['label']) }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($data['rows'] as $row)
                <tr>
                    @foreach ($data['columns'] as $key => $col)
                        @include('livewire.reports.partials.cell', ['value' => $row[$key] ?? null, 'type' => $col['type']])
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($data['columns']) }}" class="py-8 text-center text-slate-500">{{ __('No data yet.') }}</td></tr>
            @endforelse
            </tbody>
            @if (! empty($data['rows']))
                <tfoot><tr class="bg-slate-50 font-bold">
                    @foreach ($data['columns'] as $key => $col)
                        @include('livewire.reports.partials.cell', ['value' => $key === 'label' ? __('Total') : ($data['totals'][$key] ?? null), 'type' => $key === 'label' ? 'text' : $col['type']])
                    @endforeach
                </tr></tfoot>
            @endif
        </table>
    </div>
</div>
