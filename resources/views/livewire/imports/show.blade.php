<div @if ($log->isActive()) wire:poll.750ms="step" @endif>
    <x-page-header :title="__('Import').' #'.$log->id" :subtitle="$log->source_name">
        <x-slot:actions>
            @if ($log->error_report_path)<a href="{{ route('imports.report', $log) }}" class="btn-secondary">{{ __('Download error report') }}</a>@endif
            @unless (in_array($log->status, ['completed', 'cancelled', 'failed']))<button wire:click="cancel" wire:confirm="{{ __('Cancel this import?') }}" class="btn-danger">{{ __('Cancel') }}</button>@endunless
            <a href="{{ route('imports.index') }}" class="btn-secondary">{{ __('Back') }}</a>
        </x-slot:actions>
    </x-page-header>
    @foreach (['mapping', 'status', 'branch_id', 'duplicate_strategy'] as $f)
        @error($f) <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
    @endforeach
    @if ($log->last_error)<div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $log->last_error }}</div>@endif

    {{-- Progress / summary --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-3 xl:grid-cols-7">
        @foreach ([['Status', __('import.status.'.$log->status)], ['Rows', $log->total_rows], ['Valid', $log->valid_count], ['Duplicates', $log->duplicate_count],
                   ['Invalid', $log->invalid_count], ['Created', $log->created_count], ['Updated', $log->updated_count]] as [$label, $value])
            <div class="card card-body"><div class="text-xs text-slate-500">{{ __($label) }}</div><div class="text-lg font-bold">{{ $value }}</div></div>
        @endforeach
    </div>
    @if ($log->isActive())
        @php($done = $log->status === 'validating' ? $log->validated_rows : $log->processed_rows)
        <div class="card card-body mb-4">
            <div class="mb-2 text-sm">{{ $log->status === 'validating' ? __('Validating rows...') : __('Importing rows...') }} {{ $done }} / {{ $log->total_rows }}</div>
            <div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-2 bg-brand-500 transition-all" style="width: {{ $log->total_rows ? intdiv($done * 100, $log->total_rows) : 0 }}%"></div></div>
        </div>
    @endif

    @if ($log->status === 'mapping')
        <div class="card mb-6">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Column mapping') }}</div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Spreadsheet column') }}</th><th>{{ __('ERP field') }}</th><th>{{ __('Sample values') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ((array) $log->headers as $i => $h)
                    <tr wire:key="map-{{ $i }}">
                        <td class="font-medium">{{ $h }}</td>
                        <td>
                            <select wire:model="mapping.{{ $i }}" class="form-input py-1">
                                <option value="">— {{ __('Ignore') }} —</option>
                                @foreach ($fields as $f)<option value="{{ $f }}">{{ str_starts_with($f, 'stock:') ? __('Opening stock').' — '.substr($f, 6) : __('field.'.$f) }}</option>@endforeach
                            </select>
                        </td>
                        <td class="max-w-md truncate text-xs text-slate-500">{{ $preview->map(fn ($r) => $r->data[$i] ?? '')->filter()->take(3)->join(' · ') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="grid gap-4 border-t border-slate-200 p-4 sm:grid-cols-3">
                <div>
                    <label class="form-label">{{ __('Existing products (same SKU, barcode or ID)') }}</label>
                    <select wire:model.live="strategy" class="form-input">
                        <option value="skip">{{ __('Skip duplicates') }}</option>
                        <option value="update">{{ __('Update approved fields') }}</option>
                        <option value="new_only">{{ __('Import new products only') }}</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">{{ __('Branch for the "stock" column') }}</label>
                    <select wire:model="branchId" class="form-input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach</select>
                </div>
                @if ($strategy === 'update')
                    <div class="sm:col-span-3">
                        <label class="form-label">{{ __('Fields that may be overwritten') }}</label>
                        <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 p-3">
                            @foreach ($updatable as $f)<label class="flex items-center gap-1 text-sm"><input type="checkbox" wire:model="updateFields" value="{{ $f }}" class="rounded border-slate-300 text-brand-600"> {{ __('field.'.$f) }}</label>@endforeach
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ __('SKU, barcode and stock are never overwritten. Stock is only imported where the product has no stock history.') }}</p>
                    </div>
                @endif
            </div>
            <div class="flex justify-end border-t border-slate-200 p-3"><button wire:click="validateImport" class="btn-primary">{{ __('Validate') }}</button></div>
        </div>
    @endif

    @if ($log->status === 'validated')
        <div class="card card-body mb-6 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm">{{ __(':valid new, :dup existing (:strategy), :invalid with errors that will not be imported.', ['valid' => $log->valid_count, 'dup' => $log->duplicate_count, 'invalid' => $log->invalid_count, 'strategy' => __('strategy.'.$log->duplicate_strategy)]) }}</p>
            <div class="flex gap-2">
                <button wire:click="remap" class="btn-secondary">{{ __('Change mapping') }}</button>
                <button wire:click="confirmImport" wire:confirm="{{ __('Start the import now?') }}" class="btn-success" @disabled($log->valid_count + $log->duplicate_count === 0)>{{ __('Confirm import') }}</button>
            </div>
        </div>
    @endif

    @if ($log->status !== 'mapping')
        <div class="card table-wrap">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <span class="font-semibold">{{ __('Rows') }}</span>
                <select wire:model.live="filter" class="form-input w-auto py-1"><option value="problems">{{ __('Errors and warnings') }}</option><option value="all">{{ __('All rows') }}</option></select>
            </div>
            <table class="table">
                <thead><tr><th>{{ __('Row') }}</th><th>{{ __('Status') }}</th><th>{{ __('Messages') }}</th><th>{{ __('Product') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $r)
                    <tr>
                        <td>{{ $r->row_number }}</td>
                        <td><x-status-badge :color="['invalid' => 'rose', 'failed' => 'rose', 'duplicate' => 'amber', 'imported' => 'emerald', 'updated' => 'sky', 'skipped' => 'slate'][$r->status] ?? 'slate'">{{ __('import.'.$r->status) }}</x-status-badge></td>
                        <td class="whitespace-normal text-xs">
                            @foreach ((array) $r->errors as $e)<div class="text-rose-700">• {{ $e }}</div>@endforeach
                            @foreach ((array) $r->warnings as $w)<div class="text-amber-700">• {{ $w }}</div>@endforeach
                        </td>
                        <td>@if ($r->product_id)<a href="{{ route('products.show', $r->product_id) }}" class="text-brand-700">#{{ $r->product_id }}</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-slate-500">{{ __('No problems found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $rows->links() }}</div>
        </div>
    @endif
</div>
