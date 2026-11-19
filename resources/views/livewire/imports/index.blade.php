<div>
    <x-page-header :title="__('Data import')" :subtitle="__('Spreadsheets are an input only: the ERP database stays the single source of truth.')">
        <x-slot:actions>
            <a href="{{ route('imports.export', ['format' => 'xlsx']) }}" class="btn-secondary">{{ __('Export products (Excel)') }}</a>
            <a href="{{ route('imports.export', ['format' => 'csv']) }}" class="btn-secondary">{{ __('Export products (CSV)') }}</a>
        </x-slot:actions>
    </x-page-header>

    <form wire:submit="start" class="card card-body mb-6 space-y-4">
        <div class="flex flex-wrap gap-2">
            @foreach (['file' => __('Excel / CSV file'), 'google' => __('Google Sheets link'), 'url' => __('Other secure link')] as $k => $label)
                <button type="button" wire:click="$set('source', '{{ $k }}')" @class(['btn-primary btn-sm' => $source === $k, 'btn-secondary btn-sm' => $source !== $k])>{{ $label }}</button>
            @endforeach
        </div>
        @if ($source === 'file')
            <div>
                <input type="file" wire:model="file" accept=".xlsx,.csv" class="text-sm">
                <div wire:loading wire:target="file" class="text-xs text-slate-500">{{ __('Uploading...') }}</div>
                <p class="mt-1 text-xs text-slate-500">{{ __('.xlsx or .csv, first sheet, first row = column headers. Macro files (.xlsm) are refused; formulas are never executed.') }}</p>
                @error('file') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        @else
            <div>
                <input wire:model="url" class="form-input" dir="ltr" placeholder="https://docs.google.com/spreadsheets/d/...">
                <p class="mt-1 text-xs text-slate-500">
                    @if ($source === 'google')
                        {{ $googleAuth ? __('A Google service account is configured: share the sheet with it (Viewer).') : __('Share the sheet as "Anyone with the link can view", or configure a Google service account for private sheets.') }}
                    @else
                        {{ __('Only https links to hosts approved by the administrator are allowed.') }}
                    @endif
                </p>
                @error('url') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        @endif
        <button class="btn-primary" wire:loading.attr="disabled" wire:target="start">{{ __('Upload and preview') }}</button>
    </form>

    <div class="card table-wrap">
        <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Import history') }}</div>
        <table class="table">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Source') }}</th><th>{{ __('Administrator') }}</th><th>{{ __('Rows') }}</th><th>{{ __('Created') }}</th><th>{{ __('Updated') }}</th><th>{{ __('Skipped') }}</th><th>{{ __('Failed') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($logs as $l)
                <tr>
                    <td class="text-xs" dir="ltr">{{ $l->created_at->format('Y-m-d H:i') }}</td>
                    <td class="max-w-xs truncate" title="{{ $l->source_name }}">{{ __('import.source.'.$l->source_type) }} — {{ $l->source_name }}</td>
                    <td>{{ $l->user?->name }}</td>
                    <td>{{ $l->total_rows }}</td><td>{{ $l->created_count }}</td><td>{{ $l->updated_count }}</td><td>{{ $l->skipped_count }}</td><td>{{ $l->failed_count }}</td>
                    <td><x-status-badge :color="['completed' => 'emerald', 'failed' => 'rose', 'cancelled' => 'slate', 'processing' => 'sky', 'validating' => 'sky'][$l->status] ?? 'amber'">{{ __('import.status.'.$l->status) }}</x-status-badge></td>
                    <td class="text-end"><a href="{{ route('imports.show', $l) }}" class="btn-secondary btn-sm">{{ __('View') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="10" class="py-8 text-center text-slate-500">{{ __('No imports yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="p-3">{{ $logs->links() }}</div>
    </div>
</div>
