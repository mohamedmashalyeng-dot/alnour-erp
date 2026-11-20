@php
    /** @var \App\Modules\Catalog\Models\Product $product */
    $site ??= app(\App\Modules\Website\Support\SiteInfo::class);
    $available = ! $product->track_stock || (($stock[$product->id] ?? collect())->sum() > 0);
    $url = \App\Modules\Website\Http\StorefrontController::url($product);
@endphp
<article class="product-card group">
    <a href="{{ $url }}" class="block" aria-label="{{ $product->displayName() }}">
        <div class="product-card-media">
            @if ($product->primaryImage)
                <img src="{{ $product->primaryImage->url() }}" alt="{{ $product->displayName() }}" loading="lazy" class="size-full object-contain p-4 transition duration-500 group-hover:scale-105">
            @else
                <x-icon name="{{ $product->product_type?->value === 'sunglasses' ? 'sun' : 'eyeglasses' }}" class="size-16 text-slate-300" />
            @endif
            <div class="absolute inset-x-3 top-3 flex items-start justify-between gap-2">
                @if ($product->website_featured)<span class="rounded-full bg-brand-500 px-2.5 py-1 text-[11px] font-bold text-ink-900">{{ __('Featured') }}</span>@else<span></span>@endif
                @if ($site->showStock() && ! $available)<span class="rounded-full bg-white/90 px-2.5 py-1 text-[11px] font-semibold text-slate-500 ring-1 ring-slate-200">{{ __('Out of stock') }}</span>@endif
            </div>
        </div>
        <div class="p-4">
            <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
                <span class="truncate font-semibold tracking-wide text-brand-700 uppercase">{{ $product->brand?->name ?? $product->product_type?->label() }}</span>
                @if ($product->color)<span class="truncate">{{ $product->color }}</span>@endif
            </div>
            <h3 class="mt-1 line-clamp-2 min-h-[2.75rem] font-semibold leading-snug text-ink-900">{{ $product->displayName() }}</h3>
            <div class="mt-3 flex items-center justify-between">
                @if ($site->showPrices())
                    <span class="text-lg font-bold text-ink-900">{{ money($product->selling_price) }}</span>
                @else
                    <span class="text-sm font-semibold text-brand-700">{{ __('Ask for price') }}</span>
                @endif
                @if ($site->showStock() && $available)
                    <span class="flex items-center gap-1 text-xs font-medium text-emerald-700"><span class="size-1.5 rounded-full bg-emerald-500"></span>{{ __('In stock') }}</span>
                @endif
            </div>
        </div>
    </a>
</article>
