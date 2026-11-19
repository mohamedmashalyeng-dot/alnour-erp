<div>
    <x-page-header :title="__('Offline sync review')" :subtitle="__('Offline sales that need a decision, registered devices and the synchronization log.')">
        <x-slot:actions><a href="{{ route('pos.offline') }}" class="btn-secondary">{{ __('Offline POS') }}</a></x-slot:actions>
    </x-page-header>
    <div class="mb-4 flex gap-2">
        @foreach (['conflicts' => __('Conflicts'), 'devices' => __('Devices'), 'logs' => __('Sync log')] as $k => $label)
            <button wire:click="$set('tab', '{{ $k }}')" @class(['btn-primary btn-sm' => $tab === $k, 'btn-secondary btn-sm' => $tab !== $k])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'conflicts')
        <div class="card table-wrap">
            <div class="border-b border-slate-200 p-3">
                <select wire:model.live="status" class="form-input w-auto"><option value="open">{{ __('Open') }}</option><option value="resolved">{{ __('Resolved') }}</option><option value="">{{ __('All') }}</option></select>
            </div>
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Device') }}</th><th>{{ __('User') }}</th><th>{{ __('Type') }}</th><th>{{ __('Details') }}</th><th>{{ __('Invoice') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($conflicts as $c)
                    <tr wire:key="sc-{{ $c->id }}">
                        <td class="text-xs" dir="ltr">{{ $c->created_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $c->branch->code }}</td><td>{{ $c->device?->name }}</td><td>{{ $c->user?->name }}</td>
                        <td>{{ __('conflict.'.$c->type) }}</td>
                        <td class="max-w-md whitespace-normal text-xs">
                            @if (isset($c->details['message']))<div>{{ $c->details['message'] }}</div>@endif
                            @foreach (collect($c->details)->except('message')->flatten(1) as $d)
                                @if (is_array($d))<div dir="ltr">{{ $d['sku'] ?? '' }}: {{ isset($d['requested']) ? $d['requested'].' / '.$d['available'] : money($d['offline_price'] ?? 0).' ≠ '.money($d['catalog_price'] ?? 0) }}</div>@endif
                            @endforeach
                        </td>
                        <td>@if ($c->sale)<a href="{{ route('sales.show', $c->sale_id) }}" class="font-mono text-xs text-brand-700" dir="ltr">{{ $c->sale->invoice_number }}</a>@else<span class="text-xs text-rose-700">{{ __('Not recorded') }}</span>@endif</td>
                        <td>@if ($c->status === 'open')<x-status-badge color="amber">{{ __('Open') }}</x-status-badge>@else<x-status-badge color="emerald">{{ __('Resolved') }}</x-status-badge><div class="text-xs text-slate-500">{{ $c->resolution }} — {{ $c->resolver?->name }}</div>@endif</td>
                        <td>@if ($c->status === 'open')<button wire:click="startResolve({{ $c->id }})" class="btn-secondary btn-sm">{{ __('Resolve') }}</button>@endif</td>
                    </tr>
                    @if ($resolving === $c->id)
                        <tr><td colspan="9" class="bg-slate-50">
                            <form wire:submit="resolve" class="flex flex-wrap items-center gap-2">
                                <input wire:model="resolution" class="form-input max-w-xl" placeholder="{{ __('What was done (e.g. stock counted and adjusted, price approved, sale re-entered)') }}">
                                <button class="btn-primary btn-sm">{{ __('Mark as resolved') }}</button>
                                @error('resolution') <p class="form-error">{{ $message }}</p> @enderror
                            </form>
                            <p class="mt-1 text-xs text-slate-500">{{ __('Fix stock with a stock adjustment or the sale with a return/cancellation first; resolving only records the decision.') }}</p>
                        </td></tr>
                    @endif
                @empty
                    <tr><td colspan="9" class="py-8 text-center text-slate-500">{{ __('No conflicts.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $conflicts->links() }}</div>
        </div>
    @elseif ($tab === 'devices')
        <div class="card table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Device') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Registered by') }}</th><th>{{ __('Last seen') }}</th><th>{{ __('Last sync') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($devices as $d)
                    <tr><td>{{ $d->name }}<div class="max-w-xs truncate text-xs text-slate-500">{{ $d->user_agent }}</div></td><td>{{ $d->branch->code }}</td><td>{{ $d->user->name }}</td>
                        <td class="text-xs" dir="ltr">{{ $d->last_seen_at?->format('Y-m-d H:i') }}</td><td class="text-xs" dir="ltr">{{ $d->last_sync_at?->format('Y-m-d H:i') }}</td>
                        <td>@if ($d->isActive())<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Revoked') }}</x-status-badge>@endif</td>
                        <td>@if ($d->isActive())<button wire:click="revoke({{ $d->id }})" wire:confirm="{{ __('Revoke this device? It will not be able to sync until registered again.') }}" class="btn-danger btn-sm">{{ __('Revoke') }}</button>@endif</td></tr>
                @empty
                    <tr><td colspan="7" class="py-8 text-center text-slate-500">{{ __('No devices registered.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="card table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Device') }}</th><th>{{ __('User') }}</th><th>{{ __('Sale ID') }}</th><th>{{ __('Status') }}</th><th>{{ __('Message') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $l)
                    <tr><td class="text-xs" dir="ltr">{{ $l->created_at }}</td><td>{{ $l->device?->name }}</td><td>{{ $l->user?->name }}</td>
                        <td class="font-mono text-xs" dir="ltr">{{ substr($l->client_uuid, 0, 8) }}</td><td>{{ __('sync.'.$l->status) }}</td><td class="text-xs">{{ $l->message }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-slate-500">{{ __('No synchronization yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $logs->links() }}</div>
        </div>
    @endif
</div>
