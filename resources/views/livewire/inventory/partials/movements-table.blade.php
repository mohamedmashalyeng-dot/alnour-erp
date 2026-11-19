<div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th>{{ __('Date') }}</th><th>{{ __('Product') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Type') }}</th>
            <th>{{ __('Change') }}</th><th>{{ __('Before') }}</th><th>{{ __('After') }}</th><th>{{ __('Reason') }}</th><th>{{ __('User') }}</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
        @forelse ($movements as $m)
            <tr wire:key="mv-{{ $m->id }}">
                <td class="text-xs text-slate-500" dir="ltr">{{ $m->created_at?->format('Y-m-d H:i') }}</td>
                <td><div class="font-medium">{{ $m->product->displayName() }}</div><div class="font-mono text-xs text-slate-500" dir="ltr">{{ $m->product->sku }}</div></td>
                <td>{{ $m->branch->code }}</td>
                <td><x-status-badge :color="$m->quantity > 0 ? 'emerald' : 'rose'">{{ $m->type->label() }}</x-status-badge></td>
                <td class="font-semibold {{ $m->quantity > 0 ? 'text-emerald-700' : 'text-rose-700' }}" dir="ltr">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td>
                <td>{{ $m->balance_before }}</td>
                <td>{{ $m->balance_after }}</td>
                <td class="max-w-xs truncate" title="{{ $m->notes }}">{{ $m->reason ?: '—' }}</td>
                <td>{{ $m->user?->name ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-8 text-center text-slate-500">{{ __('No stock movements found.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
