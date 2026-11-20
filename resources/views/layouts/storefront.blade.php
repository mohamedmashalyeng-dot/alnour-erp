@php
    /** @var \App\Modules\Website\Support\SiteInfo $site */
    $site = app(\App\Modules\Website\Support\SiteInfo::class);
    $isRtl = app()->getLocale() === 'ar';
    $company = $site->companyName();
    $pageTitle = trim($__env->yieldContent('title'));
    $description = trim($__env->yieldContent('description')) ?: \Illuminate\Support\Str::limit((string) $site->get('hero_title').' — '.(string) $site->get('hero_subtitle'), 160);
    $ogImage = trim($__env->yieldContent('og_image')) ?: asset('images/brand/logo.png');
    $phone = $site->phone();
    $whatsapp = $site->whatsappUrl(__('Hello, I would like to ask about your products.'));
    $socials = $site->socials();
    $nav = [
        ['url' => route('shop.home'), 'label' => __('Home'), 'active' => request()->routeIs('shop.home')],
        ['url' => route('shop.products'), 'label' => __('Products'), 'active' => request()->routeIs('shop.products', 'shop.product')],
        ['url' => route('shop.home').'#services', 'label' => __('Our services'), 'active' => false],
        ['url' => route('shop.home').'#about', 'label' => __('About us'), 'active' => false],
        ['url' => route('shop.home').'#branches', 'label' => __('Our branches'), 'active' => false],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ? $pageTitle.' | '.$company : $company.' — '.$site->get('tagline') }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:site_name" content="{{ $company }}">
    <meta property="og:title" content="{{ $pageTitle ?: $company }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="#14171c">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/favicon.png') }}">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/storefront.js'])
    @stack('head')
</head>
<body class="site min-h-screen bg-white text-ink-700">
<a href="#content" class="skip-link">{{ __('Skip to content') }}</a>

{{-- Top bar --}}
<div class="hidden bg-ink-900 text-xs text-slate-300 sm:block">
    <div class="site-container flex h-9 items-center gap-5">
        @if ($site->get('hours'))<span class="flex items-center gap-1.5"><x-icon name="clock" class="size-4 text-brand-400" />{{ $site->get('hours') }}</span>@endif
        @if ($phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="flex items-center gap-1.5 hover:text-white"><x-icon name="phone" class="size-4 text-brand-400" /><span dir="ltr">{{ $phone }}</span></a>@endif
        <div class="ms-auto flex items-center gap-1">
            @foreach ($socials as $network => $url)
                <a href="{{ $url }}" target="_blank" rel="noopener" class="rounded-md p-1.5 hover:bg-white/10 hover:text-white" aria-label="{{ ucfirst($network) }}"><x-icon :name="$network" class="size-4" /></a>
            @endforeach
            <a href="{{ route('locale', $isRtl ? 'en' : 'ar') }}" class="ms-2 rounded-md px-2 py-1 font-semibold hover:bg-white/10 hover:text-white">{{ $isRtl ? 'English' : 'العربية' }}</a>
        </div>
    </div>
</div>

{{-- Header --}}
<header class="sticky top-0 z-40 border-b border-slate-200/70 bg-white/90 backdrop-blur-md" data-header>
    <div class="site-container flex h-18 items-center gap-6">
        <a href="{{ route('shop.home') }}" class="shrink-0" aria-label="{{ $company }}">
            <img src="{{ asset('images/brand/logo.png') }}" alt="{{ $company }}" class="h-9 w-auto sm:h-10">
        </a>
        <nav class="hidden items-center gap-1 lg:flex" aria-label="{{ __('Main menu') }}">
            @foreach ($nav as $item)
                <a href="{{ $item['url'] }}" @class(['site-nav-link', 'site-nav-link-active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </nav>
        <form action="{{ route('shop.products') }}" method="GET" class="ms-auto hidden md:block" role="search">
            <label class="relative block">
                <span class="sr-only">{{ __('Search products') }}</span>
                <x-icon name="search" class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-slate-400" />
                <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search glasses, brands…') }}"
                       class="w-56 rounded-full border border-slate-200 bg-slate-50 py-2 ps-9 pe-4 text-sm transition focus:w-72 focus:border-brand-400 focus:bg-white focus:ring-4 focus:ring-brand-100 focus:outline-none">
            </label>
        </form>
        @if ($whatsapp)
            <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn-primary hidden rounded-full sm:inline-flex md:ms-0 ms-auto"><x-icon name="chat" class="size-4" />{{ __('Contact us') }}</a>
        @endif
        <button type="button" class="ms-auto rounded-xl p-2 text-ink-900 hover:bg-slate-100 sm:ms-0 lg:hidden" data-menu-toggle aria-expanded="false" aria-controls="site-menu" aria-label="{{ __('Menu') }}">
            <x-icon name="bars" class="size-6" />
        </button>
    </div>
    {{-- Mobile menu --}}
    <div id="site-menu" class="hidden border-t border-slate-100 bg-white lg:hidden" data-menu>
        <div class="site-container space-y-1 py-4">
            <form action="{{ route('shop.products') }}" method="GET" class="mb-3" role="search">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search glasses, brands…') }}" class="form-input rounded-full">
            </form>
            @foreach ($nav as $item)
                <a href="{{ $item['url'] }}" @class(['block rounded-xl px-4 py-3 font-semibold', 'bg-brand-50 text-brand-700' => $item['active'], 'text-ink-800 hover:bg-slate-50' => ! $item['active']])>{{ $item['label'] }}</a>
            @endforeach
            <a href="{{ route('locale', $isRtl ? 'en' : 'ar') }}" class="block rounded-xl px-4 py-3 text-sm text-slate-500 hover:bg-slate-50">{{ $isRtl ? 'English' : 'العربية' }}</a>
        </div>
    </div>
</header>

<main id="content">
    @yield('content')
</main>

{{-- Footer --}}
<footer class="mt-20 bg-ink-900 text-slate-400">
    <div class="site-container grid gap-10 py-14 md:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-1">
            <div class="flex items-center gap-2.5">
                <img src="{{ asset('images/brand/icon.png') }}" alt="" class="h-8 w-auto">
                <span class="text-lg font-bold text-white">{{ $company }}</span>
            </div>
            @if ($site->get('tagline'))<p class="mt-3 text-lg font-semibold text-brand-400">{{ $site->get('tagline') }}</p>@endif
            @if ($socials)
                <div class="mt-5 flex gap-2">
                    @foreach ($socials as $network => $url)
                        <a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ ucfirst($network) }}" class="flex size-10 items-center justify-center rounded-full bg-white/5 text-slate-300 ring-1 ring-white/10 transition hover:bg-brand-500 hover:text-ink-900"><x-icon :name="$network" class="size-5" /></a>
                    @endforeach
                </div>
            @endif
        </div>
        <div>
            <h2 class="mb-4 font-semibold text-white">{{ __('Quick links') }}</h2>
            <ul class="space-y-2 text-sm">
                @foreach ($nav as $item)<li><a href="{{ $item['url'] }}" class="hover:text-brand-400">{{ $item['label'] }}</a></li>@endforeach
            </ul>
        </div>
        <div class="lg:col-span-2">
            <h2 class="mb-4 font-semibold text-white">{{ __('Our branches') }}</h2>
            <ul class="grid gap-4 text-sm sm:grid-cols-2">
                @foreach ($site->branches() as $b)
                    <li>
                        <div class="font-semibold text-slate-200">{{ $b->displayName() }}</div>
                        @if ($b->address)<div class="mt-0.5">{{ $b->address }}</div>@endif
                        @if ($b->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $b->phone) }}" class="mt-0.5 inline-block hover:text-brand-400" dir="ltr">{{ $b->phone }}</a>@endif
                    </li>
                @endforeach
            </ul>
            @if ($site->get('hours'))<p class="mt-4 flex items-center gap-1.5 text-sm"><x-icon name="clock" class="size-4 text-brand-400" />{{ $site->get('hours') }}</p>@endif
        </div>
    </div>
    <div class="border-t border-white/10">
        <div class="site-container flex flex-wrap items-center justify-between gap-2 py-5 text-xs">
            <span>© {{ now()->year }} {{ $company }}. {{ __('All rights reserved.') }}</span>
            <a href="{{ route('login') }}" class="text-slate-500 hover:text-slate-300">{{ __('Staff login') }}</a>
        </div>
    </div>
</footer>

@if ($whatsapp)
    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="wa-float" aria-label="{{ __('Chat with us on WhatsApp') }}">
        <x-icon name="chat" class="size-7" />
    </a>
@endif
</body>
</html>
