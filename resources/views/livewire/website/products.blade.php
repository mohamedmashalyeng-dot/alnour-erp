<div>
    <x-page-header :title="__('Website products')" :subtitle="__('Choose which products appear on the website. Prices and stock come straight from the system.')">
        <x-slot:actions>
            <button wire:click="publishInStock" wire:confirm="{{ __('Publish every product that has stock?') }}" class="btn-secondary"><x-icon name="bolt" class="size-4" /> {{ __('Publish all in stock') }}</button>
            <a href="{{ route('shop.products') }}" target="_blank" rel="noopener" class="btn-primary"><x-icon name="eye" class="size-4" /> {{ __('View website') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (['' => __('All'), 'published' => __('On website'), 'hidden' => __('Not on website'), 'featured' => __('Featured')] as $k => $label)
            <button wire:click="$set('show', '{{ $k }}')" @class(['btn-sm', 'btn-primary' => $show === $k, 'btn-secondary' => $show !== $k])>{{ $label }}</button>
        @endforeach
        <span class="text-sm text-slate-500">{{ __(':count products on the website', ['count' => $publishedCount]) }}</span>
    </div>

    <div class="card">
        <div class="flex flex-wrap gap-2 border-b border-slate-200 p-3">
            <input wire:model.live.debounce.400ms="search" type="search" class="form-input min-w-56 flex-1" placeholder="{{ __('Search by name, code or barcode') }}">
            <select wire:model.live="category" class="form-input w-auto" aria-label="{{ __('Category') }}">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->displayName() }}</option>@endforeach
            </select>
            <select wire:model.live="brand" class="form-input w-auto" aria-label="{{ __('Brand') }}">
                <option value="">{{ __('All brands') }}</option>
                @foreach ($brands as $b)<option value="{{ $b->id }}">{{ app()->getLocale() === 'en' && $b->name_en ? $b->name_en : $b->name }}</option>@endforeach
            </select>
        </div>

        @if ($selected)
            <div class="flex flex-wrap items-center gap-2 border-b border-brand-200 bg-brand-50 px-3 py-2 text-sm">
                <span class="font-semibold">{{ __(':count selected', ['count' => count($selected)]) }}</span>
                <button wire:click="bulk('publish')" class="btn-primary btn-sm">{{ __('Show on website') }}</button>
                <button wire:click="bulk('feature')" class="btn-secondary btn-sm">{{ __('Mark as featured') }}</button>
                <button wire:click="bulk('unpublish')" class="btn-secondary btn-sm">{{ __('Hide from website') }}</button>
                <button wire:click="$set('selected', [])" class="ms-auto text-slate-500 hover:text-slate-700">{{ __('Clear') }}</button>
            </div>
        @endif

        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th class="w-8"></th><th>{{ __('Product') }}</th><th>{{ __('Category') }}</th><th class="text-end">{{ __('Price') }}</th><th class="text-end">{{ __('Stock') }}</th>
                    <th class="text-center">{{ __('On website') }}</th><th class="text-center">{{ __('Featured') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($products as $p)
                    <tr wire:key="wp-{{ $p->id }}" @class(['bg-brand-50/30' => $p->show_on_website])>
                        <td><input type="checkbox" wire:model.live="selected" value="{{ $p->id }}" aria-label="{{ $p->displayName() }}"></td>
                        <td>
                            <div class="flex items-center gap-3">
                                <div class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100">
                                    @if ($p->primaryImage)<img src="{{ $p->primaryImage->url() }}" alt="" class="size-full object-cover" loading="lazy">@else<x-icon name="eyeglasses" class="size-5 text-slate-400" />@endif
                                </div>
                                <div class="min-w-0">
                                    <div class="truncate font-medium">{{ $p->displayName() }}</div>
                                    <div class="text-xs text-slate-500"><span dir="ltr">{{ $p->sku }}</span>@if ($p->brand) · {{ $p->brand->name }}@endif</div>
                                    @unless ($p->primaryImage)<div class="text-xs text-amber-600">{{ __('No photo') }}</div>@endunless
                                </div>
                            </div>
                        </td>
                        <td>{{ $p->category?->displayName() ?? '—' }}</td>
                        <td class="text-end">{{ money($p->selling_price) }}</td>
                        <td class="text-end">@if ((int) $p->stock > 0){{ (int) $p->stock }}@else<span class="text-rose-600">0</span>@endif</td>
                        <td class="text-center">
                            <button type="button" role="switch" aria-checked="{{ $p->show_on_website ? 'true' : 'false' }}" wire:click="togglePublished({{ $p->id }})"
                                    aria-label="{{ __('On website') }}: {{ $p->displayName() }}"
                                    @class(['relative inline-flex h-6 w-11 items-center rounded-full transition', 'bg-emerald-500' => $p->show_on_website, 'bg-slate-300' => ! $p->show_on_website])>
                                <span @class(['inline-block size-5 rounded-full bg-white shadow transition', 'translate-x-5.5 rtl:-translate-x-5.5' => $p->show_on_website, 'translate-x-0.5 rtl:-translate-x-0.5' => ! $p->show_on_website])></span>
                            </button>
                        </td>
                        <td class="text-center">
                            <button type="button" wire:click="toggleFeatured({{ $p->id }})" aria-pressed="{{ $p->website_featured ? 'true' : 'false' }}"
                                    title="{{ __('Featured on the home page') }}" @class(['rounded-lg p-1.5 transition hover:bg-amber-50', 'text-amber-500' => $p->website_featured, 'text-slate-300' => ! $p->website_featured])>
                                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.48 3.5a.56.56 0 011.04 0l2.13 5.11c.08.2.27.33.47.35l5.52.44c.5.04.7.66.32.99l-4.2 3.6a.56.56 0 00-.18.56l1.28 5.38a.56.56 0 01-.84.61l-4.72-2.88a.56.56 0 00-.59 0l-4.72 2.88a.56.56 0 01-.84-.61l1.28-5.38a.56.56 0 00-.18-.56l-4.2-3.6a.56.56 0 01.32-.99l5.52-.44a.56.56 0 00.47-.35z"/></svg>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-8 text-center text-slate-500">{{ __('No products found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
</div>
