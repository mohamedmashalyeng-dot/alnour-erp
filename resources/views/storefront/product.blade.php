@extends('layouts.storefront')

@php
    /** @var \App\Modules\Catalog\Models\Product $product */
    $site = app(\App\Modules\Website\Support\SiteInfo::class);
    $images = $product->images;
    $branchStock = $stock[$product->id] ?? collect();
    $available = ! $product->track_stock || $branchStock->sum() > 0;
    $url = \App\Modules\Website\Http\StorefrontController::url($product);
    $orderText = __('Hello, I am interested in: :name (code :sku)', ['name' => $product->displayName(), 'sku' => $product->sku])."\n".$url;
    $whatsapp = $site->whatsappUrl($orderText);
    $specs = array_filter([
        __('Brand') => $product->brand?->name,
        __('Type') => $product->product_type?->label(),
        __('Model') => $product->model,
        __('Color') => $product->color,
        __('Size') => $product->size,
        __('Frame') => $product->frame_type?->label(),
        __('Material') => $product->frame_material?->label(),
        __('Lens') => $product->lens_type?->label(),
        __('For') => $product->gender?->label(),
        __('Warranty') => $product->warranty_months ? __(':count months', ['count' => $product->warranty_months]) : null,
        __('Product code') => $product->sku,
    ], fn ($v) => filled($v));
@endphp

@section('title', $product->displayName())
@section('description', \Illuminate\Support\Str::limit($product->description ?: $product->displayName().' — '.$product->brand?->name, 160))
@section('og_type', 'product')
@if ($images->isNotEmpty())
    @section('og_image', $images->first()->url())
@endif

@push('head')
    <script type="application/ld+json">{!! json_encode(array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->displayName(),
        'sku' => $product->sku,
        'image' => $images->map->url()->values()->all() ?: null,
        'description' => $product->description,
        'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand->name] : null,
        'offers' => $site->showPrices() ? [
            '@type' => 'Offer',
            'price' => \App\Modules\Core\Support\Money::toDecimal($product->selling_price),
            'priceCurrency' => config('erp.currency.code'),
            'availability' => $available ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url' => $url,
        ] : null,
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endpush

@section('content')
    <div class="site-container py-6">
        <nav class="text-sm text-slate-500" aria-label="{{ __('Breadcrumb') }}">
            <a href="{{ route('shop.home') }}" class="hover:text-brand-700">{{ __('Home') }}</a> <span class="mx-1">/</span>
            <a href="{{ route('shop.products') }}" class="hover:text-brand-700">{{ __('Products') }}</a>
            @if ($product->product_type)<span class="mx-1">/</span> <a href="{{ route('shop.products', ['type' => $product->product_type->value]) }}" class="hover:text-brand-700">{{ $product->product_type->label() }}</a>@endif
        </nav>
    </div>

    <div class="site-container grid gap-10 lg:grid-cols-2 lg:gap-14">
        {{-- Gallery --}}
        <div data-gallery>
            <div class="relative flex aspect-square items-center justify-center overflow-hidden rounded-[2rem] bg-slate-50 ring-1 ring-slate-100">
                @if ($images->isNotEmpty())
                    <img src="{{ $images->first()->url() }}" alt="{{ $product->displayName() }}" class="size-full object-contain p-8" data-gallery-main>
                @else
                    <x-icon name="{{ $product->product_type?->value === 'sunglasses' ? 'sun' : 'eyeglasses' }}" class="size-32 text-slate-300" />
                @endif
                @if ($product->website_featured)<span class="absolute start-5 top-5 rounded-full bg-brand-500 px-3 py-1 text-xs font-bold text-ink-900">{{ __('Featured') }}</span>@endif
            </div>
            @if ($images->count() > 1)
                <div class="mt-4 grid grid-cols-5 gap-3">
                    @foreach ($images as $img)
                        <button type="button" data-gallery-thumb="{{ $img->url() }}" aria-label="{{ __('Photo :n', ['n' => $loop->iteration]) }}"
                                @class(['aspect-square overflow-hidden rounded-2xl bg-slate-50 ring-2 transition hover:ring-brand-300', 'ring-brand-500' => $loop->first, 'ring-transparent' => ! $loop->first])>
                            <img src="{{ $img->url() }}" alt="" class="size-full object-contain p-2" loading="lazy">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Details --}}
        <div>
            @if ($product->brand)<a href="{{ route('shop.products', ['brand' => $product->brand_id]) }}" class="text-sm font-bold tracking-wider text-brand-700 uppercase hover:underline">{{ $product->brand->name }}</a>@endif
            <h1 class="mt-2 text-3xl font-bold leading-tight text-ink-900 sm:text-4xl">{{ $product->displayName() }}</h1>
            <p class="mt-2 text-sm text-slate-500">{{ __('Code') }}: <span class="font-mono" dir="ltr">{{ $product->sku }}</span></p>

            <div class="mt-6 flex flex-wrap items-center gap-4">
                @if ($site->showPrices())
                    <span class="text-3xl font-bold text-ink-900">{{ money($product->selling_price) }}</span>
                @else
                    <span class="text-xl font-semibold text-brand-700">{{ __('Ask for price') }}</span>
                @endif
                @if ($site->showStock())
                    @if ($available)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-200"><span class="size-2 rounded-full bg-emerald-500"></span>{{ __('In stock') }}</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-600"><span class="size-2 rounded-full bg-slate-400"></span>{{ __('Out of stock — ask us') }}</span>
                    @endif
                @endif
            </div>

            <div class="mt-8 grid gap-3 sm:grid-cols-2">
                @if ($whatsapp)
                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn rounded-full bg-emerald-600 py-3.5 text-base text-white shadow-sm hover:bg-emerald-700"><x-icon name="chat" class="size-5" />{{ __('Order on WhatsApp') }}</a>
                @endif
                <a href="#inquiry" class="btn-primary rounded-full py-3.5 text-base @unless ($whatsapp) sm:col-span-2 @endunless"><x-icon name="building" class="size-5" />{{ __('Reserve at a branch') }}</a>
            </div>

            @if ($site->showStock() && $product->track_stock && $site->branches()->count() > 1)
                <div class="mt-8 rounded-2xl border border-slate-200 p-5">
                    <h2 class="mb-3 font-semibold text-ink-900">{{ __('Availability by branch') }}</h2>
                    <ul class="space-y-2 text-sm">
                        @foreach ($site->branches() as $b)
                            @php $has = ($branchStock[$b->id] ?? 0) > 0; @endphp
                            <li class="flex items-center justify-between gap-3">
                                <span class="flex items-center gap-2"><x-icon name="map-pin" class="size-4 text-slate-400" />{{ $b->displayName() }}</span>
                                <span @class(['font-semibold', 'text-emerald-700' => $has, 'text-slate-400' => ! $has])>{{ $has ? __('In stock') : __('Not available') }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($specs)
                <div class="mt-8">
                    <h2 class="mb-3 font-semibold text-ink-900">{{ __('Specifications') }}</h2>
                    <dl class="divide-y divide-slate-100 rounded-2xl border border-slate-200 text-sm">
                        @foreach ($specs as $label => $value)
                            <div class="flex justify-between gap-4 px-5 py-3"><dt class="text-slate-500">{{ $label }}</dt><dd class="text-end font-medium text-ink-900" @if ($label === __('Product code') || $label === __('Size')) dir="ltr" @endif>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            @endif

            @if (filled($product->description))
                <div class="mt-8">
                    <h2 class="mb-2 font-semibold text-ink-900">{{ __('Description') }}</h2>
                    <p class="leading-relaxed whitespace-pre-line text-slate-600">{{ $product->description }}</p>
                </div>
            @endif

            <ul class="mt-8 grid gap-3 text-sm text-slate-600 sm:grid-cols-3">
                <li class="flex items-center gap-2"><x-icon name="shield" class="size-5 text-brand-600" />{{ __('Genuine product') }}</li>
                <li class="flex items-center gap-2"><x-icon name="wrench" class="size-5 text-brand-600" />{{ __('Free after-sales service') }}</li>
                <li class="flex items-center gap-2"><x-icon name="eye" class="size-5 text-brand-600" />{{ __('Free eye check') }}</li>
            </ul>
        </div>
    </div>

    {{-- Reserve form --}}
    <section class="site-container mt-16">
        <div class="grid gap-8 rounded-[2rem] bg-slate-50 p-6 ring-1 ring-slate-100 sm:p-10 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <h2 class="section-title">{{ __('Reserve it, then try it in the branch') }}</h2>
                <p class="mt-3 text-slate-600">{{ __('Leave your number and we will hold this product for you and call you to arrange your visit.') }}</p>
            </div>
            <div class="rounded-2xl bg-white p-6 shadow-sm lg:col-span-3">
                @include('storefront.partials.inquiry-form', ['site' => $site, 'product' => $product])
            </div>
        </div>
    </section>

    @if ($related->isNotEmpty())
        <section class="site-container mt-16">
            <div class="section-head">
                <h2 class="section-title">{{ __('You may also like') }}</h2>
                <a href="{{ route('shop.products', array_filter(['category' => $product->category_id])) }}" class="section-link">{{ __('View more') }} <x-icon name="{{ app()->getLocale() === 'ar' ? 'chevron-left' : 'chevron-right' }}" class="size-4" /></a>
            </div>
            <div class="grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-4">
                @foreach ($related as $r)
                    @include('storefront.partials.product-card', ['product' => $r, 'stock' => $stock, 'site' => $site])
                @endforeach
            </div>
        </section>
    @endif
@endsection
