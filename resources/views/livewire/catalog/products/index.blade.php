<div>
    <x-page-header :title="__('Products')">
        <x-slot:actions>
            @can('products.print-labels')
                <button wire:click="printLabels" class="btn-secondary" @disabled(count($selected) === 0)>
                    <x-icon name="printer" class="size-4" />{{ __('Print labels') }} @if (count($selected)) ({{ count($selected) }}) @endif
                </button>
            @endcan
            @can('products.create')
                <a href="{{ route('products.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New product') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="grid gap-3 border-b border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <input wire:model.live.debounce.300ms="search" type="search" class="form-input" placeholder="{{ __('Search by name, SKU, barcode or model') }}" autofocus>
            </div>
            <select wire:model.live="category" class="form-input">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->displayName() }}</option>@endforeach
            </select>
            <select wire:model.live="brand" class="form-input">
                <option value="">{{ __('All brands') }}</option>
                @foreach ($brands as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
            </select>
            <div class="flex gap-2">
                <select wire:model.live="type" class="form-input">
                    <option value="">{{ __('All types') }}</option>
                    @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                <select wire:model.live="status" class="form-input">
                    <option value="all">{{ __('All') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th class="w-8"></th>
                    <th>{{ __('Product') }}</th><th>{{ __('SKU') }}</th><th>{{ __('Barcode') }}</th>
                    <th>{{ __('Category') }}</th><th>{{ __('Brand') }}</th>
                    @can('products.view-cost')<th>{{ __('Purchase price') }}</th>@endcan
                    <th>{{ __('Selling price') }}</th><th>{{ __('Stock') }}</th><th>{{ __('Status') }}</th><th></th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($products as $p)
                    <tr wire:key="p-{{ $p->id }}">
                        <td><input type="checkbox" wire:model.live="selected" value="{{ $p->id }}" class="rounded border-slate-300 text-brand-600" aria-label="{{ __('Select') }}"></td>
                        <td>
                            <div class="flex items-center gap-3">
                                @if ($p->primaryImage)
                                    <img src="{{ $p->primaryImage->url() }}" alt="" class="size-10 rounded-lg object-cover" loading="lazy">
                                @else
                                    <div class="flex size-10 items-center justify-center rounded-lg bg-slate-100 text-slate-400"><x-icon name="cube" /></div>
                                @endif
                                <div>
                                    <a href="{{ route('products.show', $p) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $p->displayName() }}</a>
                                    <div class="text-xs text-slate-500">{{ $p->product_type->label() }} {{ $p->model ? '· '.$p->model : '' }} {{ $p->color ? '· '.$p->color : '' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="font-mono text-xs" dir="ltr">{{ $p->sku }}</td>
                        <td class="font-mono text-xs" dir="ltr">{{ $p->barcode }}</td>
                        <td>{{ $p->category?->displayName() ?? '—' }}</td>
                        <td>{{ $p->brand?->displayName() ?? '—' }}</td>
                        @can('products.view-cost')<td>{{ money($p->purchase_price) }}</td>@endcan
                        <td class="font-semibold">{{ money($p->selling_price) }}</td>
                        <td>{{ $p->track_stock ? number_format((int) $p->stock) : '—' }}</td>
                        <td>@if ($p->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</td>
                        <td class="text-end">
                            <a href="{{ route('products.show', $p) }}" class="btn-secondary btn-sm" title="{{ __('View') }}"><x-icon name="eye" class="size-4" /></a>
                            @can('products.update')
                                <a href="{{ route('products.edit', $p) }}" class="btn-secondary btn-sm" title="{{ __('Edit') }}"><x-icon name="pencil" class="size-4" /></a>
                            @endcan
                            @can('products.toggle')
                                <button wire:click="toggle({{ $p->id }})" wire:confirm="{{ __('Are you sure?') }}" class="btn-secondary btn-sm">{{ $p->is_active ? __('Deactivate') : __('Activate') }}</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="py-10 text-center text-slate-500">{{ __('No products found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $products->links() }}</div>
    </div>
</div>
