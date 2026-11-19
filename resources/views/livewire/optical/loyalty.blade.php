<div>
    <x-page-header :title="__('Loyalty program')" :subtitle="__('Points are earned on completed sales and redeemed as an invoice discount at the POS.')" />
    @if (! $enabled)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('The loyalty program is disabled. An administrator can enable it in Settings.') }}</div>
    @endif
    <div class="grid gap-4 lg:grid-cols-5">
        <div class="card table-wrap lg:col-span-2">
            <div class="border-b border-slate-200 p-3"><input wire:model.live.debounce.400ms="search" class="form-input" placeholder="{{ __('Search customer') }}"></div>
            <table class="table">
                <thead><tr><th>{{ __('Customer') }}</th><th class="text-end">{{ __('Points') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($customers as $c)
                    <tr wire:key="lc-{{ $c->id }}" wire:click="select({{ $c->id }})" @class(['cursor-pointer hover:bg-slate-50', 'bg-brand-50' => $selected?->id === $c->id])>
                        <td>{{ $c->name }}<div class="text-xs text-slate-500" dir="ltr">{{ $c->phone }}</div></td>
                        <td class="text-end font-semibold">{{ number_format($c->loyalty_points) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="py-8 text-center text-slate-500">{{ __('No customers.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $customers->links() }}</div>
        </div>
        <div class="lg:col-span-3">
            @if ($selected)
                <div class="mb-4 grid gap-3 sm:grid-cols-3">
                    <x-stat :label="__('Balance')" :value="number_format($selected->loyalty_points).' '.__('points')" icon="gift" tone="brand" />
                    <x-stat :label="__('Worth')" :value="money($loyalty->value($selected->loyalty_points))" icon="cash" tone="emerald" />
                    <x-stat :label="__('Tier')" :value="__('tier.'.$tier['name'])" icon="star" tone="amber" />
                </div>
                @can('loyalty.adjust')
                    <form wire:submit="saveAdjustment" class="card card-body mb-4 flex flex-wrap items-start gap-2">
                        <input wire:model="adjust.points" class="form-input w-32" dir="ltr" placeholder="+100 / -50">
                        <input wire:model="adjust.notes" class="form-input max-w-sm" placeholder="{{ __('Reason (required)') }}">
                        <button class="btn-secondary">{{ __('Adjust points') }}</button>
                        @error('adjust.points') <p class="form-error w-full">{{ $message }}</p> @enderror
                    </form>
                @endcan
                <div class="card table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th class="text-end">{{ __('Points') }}</th><th class="text-end">{{ __('Balance') }}</th><th>{{ __('Notes') }}</th><th>{{ __('User') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @forelse ($ledger as $t)
                            <tr><td class="text-xs" dir="ltr">{{ $t->created_at?->format('Y-m-d H:i') }}</td><td>{{ __('loyalty.'.$t->type) }}</td>
                                <td class="text-end {{ $t->points < 0 ? 'text-rose-700' : 'text-emerald-700' }}" dir="ltr">{{ $t->points > 0 ? '+' : '' }}{{ number_format($t->points) }}</td>
                                <td class="text-end">{{ number_format($t->balance_after) }}</td><td class="text-xs">{{ $t->notes }}</td><td class="text-xs">{{ $t->user?->name }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-slate-500">{{ __('No points activity yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <div class="card card-body text-center text-slate-500">{{ __('Select a customer to see their points.') }}</div>
            @endif
        </div>
    </div>
</div>
