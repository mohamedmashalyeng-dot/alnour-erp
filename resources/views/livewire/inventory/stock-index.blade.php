<div>
    <x-page-header :title="__('Stock levels')" :subtitle="$branch ? $branches->firstWhere('id', $branch)?->displayName() : __('Combined stock across all branches')">
        <x-slot:actions>
            @canany(['inventory.opening-stock', 'inventory.receive', 'inventory.adjust'])
                <a href="{{ route('inventory.operations') }}" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('Stock operations') }}</a>
            @endcanany
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('Units on hand')" :value="number_format((int) $totals->qty)" icon="archive" />
        <x-stat :label="__('Reserved units')" :value="number_format((int) $totals->reserved)" icon="clock" tone="sky" />
        <x-stat :label="__('Low-stock alerts')" :value="number_format((int) $totals->low)" icon="alert" tone="rose" />
        @if ($valuation !== null)<x-stat :label="__('Stock value (cost)')" :value="money($valuation)" icon="cash" tone="emerald" />@endif
    </div>

    <div class="card">
        <div class="grid gap-3 border-b border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-4">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input" placeholder="{{ __('Search by barcode, SKU or name') }}" autofocus>
            <select wire:model.live="branch" class="form-input">
                @if ($canAll)<option value="0">{{ __('All branches (combined)') }}</option>@endif
                @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
            </select>
            <select wire:model.live="category" class="form-input">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->displayName() }}</option>@endforeach
            </select>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="lowOnly" class="rounded border-slate-300 text-brand-600"> {{ __('Low stock only') }}</label>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th>{{ __('Product') }}</th><th>{{ __('SKU') }}</th><th>{{ __('Barcode') }}</th><th>{{ __('Category') }}</th>
                    <th>{{ __('On hand') }}</th><th>{{ __('Reserved') }}</th><th>{{ __('Available') }}</th><th>{{ __('Minimum') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $r)
                    <tr wire:key="s-{{ $r->id }}-{{ $branch }}">
                        <td><a href="{{ route('products.show', $r->id) }}" class="font-medium hover:text-brand-700">{{ $r->displayName() }}</a></td>
                        <td class="font-mono text-xs" dir="ltr">{{ $r->sku }}</td>
                        <td class="font-mono text-xs" dir="ltr">{{ $r->barcode }}</td>
                        <td>{{ $r->category?->displayName() ?? '—' }}</td>
                        <td class="font-semibold {{ $r->low_rows > 0 ? 'text-rose-600' : '' }}">
                            {{ $r->qty }} @if ($r->low_rows > 0)<x-icon name="alert" class="inline size-4" />@endif
                        </td>
                        <td>{{ $r->reserved }}</td>
                        <td>{{ $r->qty - $r->reserved }}</td>
                        <td>
                            @if ($branch && auth()->user()->can('inventory.adjust'))
                                <div class="flex items-center gap-1">
                                    <input type="number" min="0" wire:model="minLevels.{{ $r->id }}" class="form-input w-20 py-1" aria-label="{{ __('Minimum') }}">
                                    <button wire:click="saveMinLevel({{ $r->id }})" class="btn-secondary btn-sm">{{ __('Save') }}</button>
                                </div>
                                @error('minLevels.'.$r->id) <p class="form-error">{{ $message }}</p> @enderror
                            @else
                                {{ $r->min_level }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-10 text-center text-slate-500">{{ __('No stock found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $rows->links() }}</div>
    </div>
</div>
