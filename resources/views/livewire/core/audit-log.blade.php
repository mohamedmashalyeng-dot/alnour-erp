<div>
    <x-page-header :title="__('Audit log')" :subtitle="__('Immutable record of sensitive actions.')" />
    <div class="card">
        <div class="border-b border-slate-200 p-3">
            <select wire:model.live="event" class="form-input max-w-xs">
                <option value="">{{ __('All events') }}</option>
                @foreach (['auth', 'product', 'inventory', 'transfer', 'branch', 'user', 'supplier', 'customer', 'purchase', 'sale', 'return', 'payment', 'settings'] as $prefix)
                    <option value="{{ $prefix }}.">{{ __('audit.'.$prefix) }}</option>
                @endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('User') }}</th><th>{{ __('Event') }}</th><th>{{ __('Record') }}</th><th>{{ __('IP') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($logs as $log)
                    <tr wire:key="log-{{ $log->id }}">
                        <td class="text-xs" dir="ltr">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $log->user?->name ?? '—' }}</td>
                        <td class="font-mono text-xs" dir="ltr">{{ $log->event }}</td>
                        <td class="font-mono text-xs" dir="ltr">{{ $log->auditable_type ? $log->auditable_type.'#'.$log->auditable_id : '—' }}</td>
                        <td class="font-mono text-xs" dir="ltr">{{ $log->ip_address }}</td>
                        <td class="text-end">
                            @if ($log->old_values || $log->new_values)
                                <button wire:click="$set('expanded', {{ $expanded === $log->id ? 'null' : $log->id }})" class="btn-secondary btn-sm">{{ __('Details') }}</button>
                            @endif
                        </td>
                    </tr>
                    @if ($expanded === $log->id)
                        <tr><td colspan="6" class="bg-slate-50">
                            <div class="grid gap-3 md:grid-cols-2" dir="ltr">
                                <pre class="overflow-x-auto rounded bg-white p-2 text-xs whitespace-pre-wrap">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                <pre class="overflow-x-auto rounded bg-white p-2 text-xs whitespace-pre-wrap">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        </td></tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $logs->links() }}</div>
    </div>
</div>
