@extends('layouts.storefront')

@php
    $site = app(\App\Modules\Website\Support\SiteInfo::class);
    $active = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== false);
    unset($active['sort']);
    $chip = function (string $key) use ($filters) {
        return route('shop.products', array_filter(array_diff_key($filters, [$key => 1]), fn ($v) => $v !== null && $v !== ''));
    };
    $title = __('Products');
    if (! empty($filters['type'])) {
        $title = \App\Modules\Catalog\Enums\ProductType::from($filters['type'])->label();
    } elseif (! empty($filters['brand']) && ($b = $facets['brands']->firstWhere('id', (int) $filters['brand']))) {
        $title = $b->name;
    } elseif (! empty($filters['category']) && ($c = $facets['categories']->firstWhere('id', (int) $filters['category']))) {
        $title = $c->displayName();
    }
@endphp

@section('title', $title)
@section('description', __('Browse :count products: prescription glasses, sunglasses and lenses from top brands.', ['count' => $products->total()]))

@section('content')
    <div class="border-b border-slate-100 bg-slate-50">
        <div class="site-container py-8">
            <nav class="text-sm text-slate-500" aria-label="{{ __('Breadcrumb') }}">
                <a href="{{ route('shop.home') }}" class="hover:text-brand-700">{{ __('Home') }}</a> <span class="mx-1">/</span> <span class="text-ink-800">{{ __('Products') }}</span>
            </nav>
            <h1 class="mt-2 text-3xl font-bold text-ink-900">{{ $title }}</h1>
            <p class="mt-1 text-slate-500">{{ __(':count products', ['count' => $products->total()]) }}</p>
        </div>
    </div>

    <div class="site-container grid gap-8 py-8 lg:grid-cols-[16rem_1fr]">
        {{-- Filters --}}
        <aside>
            <details class="group rounded-2xl border border-slate-200 lg:open:border-0 lg:border-0" @if (! empty($active)) open @endif data-filters>
                <summary class="flex cursor-pointer list-none items-center justify-between p-4 font-semibold lg:hidden">
                    <span class="flex items-center gap-2"><x-icon name="adjustments" class="size-5" />{{ __('Filter products') }} @if ($active)<span class="rounded-full bg-brand-500 px-2 text-xs text-ink-900">{{ count($active) }}</span>@endif</span>
                    <x-icon name="chevron-down" class="size-5 transition group-open:rotate-180" />
                </summary>
                <form method="GET" action="{{ route('shop.products') }}" class="space-y-6 p-4 pt-0 lg:p-0" data-autosubmit>
                    @if (! empty($filters['q']))<input type="hidden" name="q" value="{{ $filters['q'] }}">@endif
                    @if (! empty($filters['sort']))<input type="hidden" name="sort" value="{{ $filters['sort'] }}">@endif

                    @if ($facets['types']->count() > 1)
                        <fieldset>
                            <legend class="filter-title">{{ __('Type') }}</legend>
                            <div class="space-y-1">
                                <label class="filter-option"><input type="radio" name="type" value="" @checked(empty($filters['type']))> <span>{{ __('All') }}</span></label>
                                @foreach ($facets['types'] as $value => $t)
                                    <label class="filter-option"><input type="radio" name="type" value="{{ $value }}" @checked(($filters['type'] ?? '') === $value)> <span class="flex-1">{{ $t['label'] }}</span><span class="text-xs text-slate-400">{{ $t['count'] }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif

                    @if ($facets['brands']->isNotEmpty())
                        <fieldset>
                            <legend class="filter-title">{{ __('Brand') }}</legend>
                            <select name="brand" class="form-input">
                                <option value="">{{ __('All brands') }}</option>
                                @foreach ($facets['brands'] as $b)<option value="{{ $b->id }}" @selected(($filters['brand'] ?? '') == $b->id)>{{ $b->name }} ({{ $b->products_count }})</option>@endforeach
                            </select>
                        </fieldset>
                    @endif

                    @if ($facets['categories']->count() > 1)
                        <fieldset>
                            <legend class="filter-title">{{ __('Category') }}</legend>
                            <select name="category" class="form-input">
                                <option value="">{{ __('All categories') }}</option>
                                @foreach ($facets['categories'] as $c)<option value="{{ $c->id }}" @selected(($filters['category'] ?? '') == $c->id)>{{ $c->displayName() }} ({{ $c->products_count }})</option>@endforeach
                            </select>
                        </fieldset>
                    @endif

                    <fieldset>
                        <legend class="filter-title">{{ __('For') }}</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Modules\Catalog\Enums\Gender::cases() as $g)
                                <label class="choice choice-sm">
                                    <input type="radio" name="gender" value="{{ $g->value }}" class="peer sr-only" @checked(($filters['gender'] ?? '') === $g->value)>
                                    <span class="choice-box">{{ $g->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="filter-title">{{ __('Frame') }}</legend>
                        <select name="frame" class="form-input">
                            <option value="">{{ __('Any frame') }}</option>
                            @foreach (\App\Modules\Catalog\Enums\FrameType::cases() as $f)<option value="{{ $f->value }}" @selected(($filters['frame'] ?? '') === $f->value)>{{ $f->label() }}</option>@endforeach
                        </select>
                    </fieldset>

                    @if ($site->showPrices())
                        <fieldset>
                            <legend class="filter-title">{{ __('Price') }}</legend>
                            <div class="flex items-center gap-2">
                                <input name="min" value="{{ $filters['min'] ?? '' }}" inputmode="decimal" placeholder="{{ __('From') }}" class="form-input" dir="ltr" aria-label="{{ __('Minimum price') }}">
                                <span class="text-slate-400">—</span>
                                <input name="max" value="{{ $filters['max'] ?? '' }}" inputmode="decimal" placeholder="{{ __('To') }}" class="form-input" dir="ltr" aria-label="{{ __('Maximum price') }}">
                            </div>
                        </fieldset>
                    @endif

                    @if ($site->showStock())
                        <label class="filter-option"><input type="checkbox" name="available" value="1" @checked(! empty($filters['available']))> <span>{{ __('Available now only') }}</span></label>
                    @endif

                    <div class="flex gap-2">
                        <button class="btn-primary flex-1 rounded-full">{{ __('Apply') }}</button>
                        @if ($active)<a href="{{ route('shop.products') }}" class="btn-secondary rounded-full">{{ __('Clear') }}</a>@endif
                    </div>
                </form>
            </details>
        </aside>

        {{-- Results --}}
        <div>
            <div class="mb-5 flex flex-wrap items-center gap-2">
                @foreach ($active as $key => $value)
                    @php
                        $label = match ($key) {
                            'q' => '“'.$value.'”',
                            'type' => \App\Modules\Catalog\Enums\ProductType::from($value)->label(),
                            'gender' => \App\Modules\Catalog\Enums\Gender::from($value)->label(),
                            'frame' => \App\Modules\Catalog\Enums\FrameType::from($value)->label(),
                            'brand' => $facets['brands']->firstWhere('id', (int) $value)?->name ?? __('Brand'),
                            'category' => $facets['categories']->firstWhere('id', (int) $value)?->displayName() ?? __('Category'),
                            'min' => __('From').' '.$value,
                            'max' => __('To').' '.$value,
                            'available' => __('Available now only'),
                            default => $value,
                        };
                    @endphp
                    <a href="{{ $chip($key) }}" class="inline-flex items-center gap-1.5 rounded-full bg-ink-900 px-3 py-1.5 text-sm text-white hover:bg-ink-800">{{ $label }} <x-icon name="x" class="size-3.5" /></a>
                @endforeach
                <form method="GET" action="{{ route('shop.products') }}" class="ms-auto flex items-center gap-2">
                    @foreach (array_diff_key($filters, ['sort' => 1]) as $k => $v)@if ($v !== null && $v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
                    <label for="sort" class="text-sm text-slate-500">{{ __('Sort by') }}</label>
                    <select id="sort" name="sort" class="form-input w-auto rounded-full py-1.5" onchange="this.form.submit()">
                        @foreach (['newest' => __('Newest'), 'price_asc' => __('Price: low to high'), 'price_desc' => __('Price: high to low'), 'name' => __('Name')] as $k => $label)
                            @if ($site->showPrices() || ! str_starts_with($k, 'price'))
                                <option value="{{ $k }}" @selected(($filters['sort'] ?? 'newest') === $k)>{{ $label }}</option>
                            @endif
                        @endforeach
                    </select>
                    <noscript><button class="btn-secondary btn-sm">{{ __('Apply') }}</button></noscript>
                </form>
            </div>

            @if ($products->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 p-12 text-center">
                    <x-icon name="search" class="mx-auto size-10 text-slate-300" />
                    <h2 class="mt-3 text-lg font-bold text-ink-900">{{ __('No products match your search') }}</h2>
                    <p class="mt-1 text-slate-500">{{ __('Try other filters, or ask us — we may have it in a branch.') }}</p>
                    <a href="{{ route('shop.products') }}" class="btn-primary mt-5 rounded-full">{{ __('Show all products') }}</a>
                </div>
            @else
                <div class="grid grid-cols-2 gap-4 sm:gap-6 xl:grid-cols-3">
                    @foreach ($products as $product)
                        @include('storefront.partials.product-card', ['product' => $product, 'stock' => $stock, 'site' => $site])
                    @endforeach
                </div>
                <div class="mt-10">{{ $products->links() }}</div>
            @endif
        </div>
    </div>
@endsection
