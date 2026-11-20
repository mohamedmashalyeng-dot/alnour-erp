@extends('layouts.storefront')

@php
    $site = app(\App\Modules\Website\Support\SiteInfo::class);
    $typeIcons = ['frame' => 'eyeglasses', 'sunglasses' => 'sun', 'ophthalmic_lens' => 'eye', 'contact_lens' => 'eye', 'solution' => 'sparkles', 'accessory' => 'gift'];
    $services = [
        ['icon' => 'eyeglasses', 'title' => __('Prescription glasses'), 'text' => __('Frames from top brands, fitted to your prescription and face shape.')],
        ['icon' => 'sun', 'title' => __('Sunglasses'), 'text' => __('Original sunglasses with full UV protection, for every style.')],
        ['icon' => 'eye', 'title' => __('Prescription lenses'), 'text' => __('Single-vision, bifocal and progressive lenses with the latest coatings.')],
        ['icon' => 'sparkles', 'title' => __('Contact lenses'), 'text' => __('Daily, monthly and coloured contact lenses with care solutions.')],
        ['icon' => 'heart', 'title' => __('Prosthetic eyes'), 'text' => __('Custom-made prosthetic eyes with a natural look and comfortable fit.')],
        ['icon' => 'user', 'title' => __('Eye consultations'), 'text' => __('Computerised eye exams and advice from our specialists.')],
    ];
    $whyUs = [
        ['icon' => 'heart', 'title' => __('Care for your eye health')],
        ['icon' => 'wrench', 'title' => __('Free after-sales service')],
        ['icon' => 'shield', 'title' => __('Genuine, quality products')],
        ['icon' => 'user', 'title' => __('Medical consultations')],
        ['icon' => 'map-pin', 'title' => __('Branches near you')],
        ['icon' => 'sparkles', 'title' => __('Variety and innovation')],
    ];
    $values = [__('Care'), __('Quality'), __('Trust'), __('Fair prices')];
    $examWhatsapp = $site->whatsappUrl(__('Hello, I would like to book an eye exam.'));
@endphp

@section('content')
    {{-- Hero --}}
    <section class="brand-backdrop relative overflow-hidden text-white">
        <div class="site-container relative grid items-center gap-12 py-16 sm:py-20 lg:grid-cols-2 lg:py-28">
            <div>
                <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-sm text-brand-300 ring-1 ring-white/15"><x-icon name="sparkles" class="size-4" />{{ $site->get('tagline') ?: $site->companyName() }}</span>
                <h1 class="mt-5 text-3xl leading-tight font-bold sm:text-4xl lg:text-5xl lg:leading-tight">{{ $site->get('hero_title') }}</h1>
                @if ($site->get('hero_subtitle'))<p class="mt-5 max-w-xl text-lg text-slate-300">{{ $site->get('hero_subtitle') }}</p>@endif
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('shop.products') }}" class="btn-primary rounded-full px-6 py-3 text-base">{{ __('Browse products') }} <x-icon name="{{ app()->getLocale() === 'ar' ? 'chevron-left' : 'chevron-right' }}" class="size-4" /></a>
                    <a href="#book" class="btn rounded-full bg-white/10 px-6 py-3 text-base text-white ring-1 ring-white/20 hover:bg-white/15">{{ __('Book an eye exam') }}</a>
                </div>
                <dl class="mt-12 grid max-w-md grid-cols-3 gap-6 border-t border-white/10 pt-6">
                    <div><dt class="text-xs text-slate-400">{{ __('Branches') }}</dt><dd class="mt-1 text-2xl font-bold">{{ $site->branches()->count() }}</dd></div>
                    <div><dt class="text-xs text-slate-400">{{ __('Brands') }}</dt><dd class="mt-1 text-2xl font-bold">{{ $facets['brands']->count() }}+</dd></div>
                    <div><dt class="text-xs text-slate-400">{{ __('After-sales') }}</dt><dd class="mt-1 text-2xl font-bold text-brand-400">{{ __('Free') }}</dd></div>
                </dl>
            </div>

            {{-- Visual: featured product photo, or a brand illustration --}}
            <div class="relative mx-auto w-full max-w-md lg:max-w-none">
                <div class="absolute inset-6 rounded-full bg-brand-500/25 blur-3xl"></div>
                <div class="relative aspect-square rounded-[2.5rem] bg-gradient-to-br from-white/10 to-white/[0.02] p-8 ring-1 ring-white/15 backdrop-blur">
                    @php $heroProduct = $featured->first(fn ($p) => $p->primaryImage); @endphp
                    @if ($heroProduct)
                        <img src="{{ $heroProduct->primaryImage->url() }}" alt="{{ $heroProduct->displayName() }}" class="size-full object-contain drop-shadow-2xl">
                    @else
                        <svg viewBox="0 0 240 120" class="size-full text-brand-500" fill="none" stroke="currentColor" stroke-width="7" stroke-linecap="round" aria-hidden="true">
                            <circle cx="62" cy="64" r="40" /><circle cx="178" cy="64" r="40" /><path d="M102 60c10-10 26-10 36 0" /><path d="M22 58 8 36M218 58l14-22" />
                            <path d="M44 50a22 22 0 0 1 18-8" stroke="white" stroke-opacity=".5" stroke-width="4" /><path d="M160 50a22 22 0 0 1 18-8" stroke="white" stroke-opacity=".5" stroke-width="4" />
                        </svg>
                    @endif
                    <div class="absolute -bottom-4 start-6 flex items-center gap-3 rounded-2xl bg-white px-4 py-3 text-ink-900 shadow-xl">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-brand-100 text-brand-700"><x-icon name="shield" class="size-5" /></span>
                        <div><div class="text-sm font-bold">{{ __('Free after-sales service') }}</div><div class="text-xs text-slate-500">{{ __('Adjustments and cleaning, anytime') }}</div></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Shop by type --}}
    @if ($facets['types']->isNotEmpty())
        <section class="site-container py-16">
            <div class="section-head">
                <div><p class="section-kicker">{{ __('Shop by category') }}</p><h2 class="section-title">{{ __('What are you looking for?') }}</h2></div>
            </div>
            <div @class(['grid grid-cols-2 gap-4 sm:grid-cols-3', [3 => 'lg:grid-cols-3', 4 => 'lg:grid-cols-4', 5 => 'lg:grid-cols-5', 6 => 'lg:grid-cols-6'][min(6, max(3, $facets['types']->count()))]])>
                @foreach ($facets['types'] as $value => $t)
                    <a href="{{ route('shop.products', ['type' => $value]) }}" class="group flex flex-col items-center gap-3 rounded-3xl bg-slate-50 p-6 text-center ring-1 ring-slate-100 transition hover:-translate-y-1 hover:bg-brand-50 hover:ring-brand-200">
                        <span class="flex size-16 items-center justify-center rounded-2xl bg-white text-ink-900 shadow-sm transition group-hover:bg-brand-500"><x-icon :name="$typeIcons[$value] ?? 'eyeglasses'" class="size-8" /></span>
                        <span class="font-semibold text-ink-900">{{ $t['label'] }}</span>
                        <span class="text-xs text-slate-500">{{ __(':count products', ['count' => $t['count']]) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Featured products --}}
    @if ($featured->isNotEmpty())
        <section class="bg-slate-50 py-16">
            <div class="site-container">
                <div class="section-head">
                    <div><p class="section-kicker">{{ __('Our collection') }}</p><h2 class="section-title">{{ __('Featured products') }}</h2></div>
                    <a href="{{ route('shop.products') }}" class="section-link">{{ __('View all products') }} <x-icon name="{{ app()->getLocale() === 'ar' ? 'chevron-left' : 'chevron-right' }}" class="size-4" /></a>
                </div>
                <div class="grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-4">
                    @foreach ($featured as $product)
                        @include('storefront.partials.product-card', ['product' => $product, 'stock' => $stock, 'site' => $site])
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Services --}}
    <section id="services" class="site-container scroll-mt-24 py-16">
        <div class="section-head justify-center text-center">
            <div><p class="section-kicker">{{ __('Our services') }}</p><h2 class="section-title">{{ __('Everything your eyes need, in one place') }}</h2></div>
        </div>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($services as $s)
                <div class="group rounded-3xl border border-slate-200 bg-white p-6 transition hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-500/5">
                    <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 transition group-hover:bg-brand-500 group-hover:text-ink-900"><x-icon :name="$s['icon']" class="size-6" /></span>
                    <h3 class="mt-4 text-lg font-bold text-ink-900">{{ $s['title'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $s['text'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- About + values --}}
    <section id="about" class="scroll-mt-24 bg-ink-900 py-20 text-white">
        <div class="site-container grid items-center gap-12 lg:grid-cols-2">
            <div>
                <p class="section-kicker">{{ __('About us') }}</p>
                <h2 class="section-title text-white">{{ $site->companyName() }}</h2>
                <p class="mt-5 text-lg leading-relaxed text-slate-300">{{ $site->get('about') }}</p>
                <div class="mt-8 flex flex-wrap gap-2">
                    @foreach ($values as $v)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/5 px-4 py-2 text-sm font-semibold ring-1 ring-white/10"><x-icon name="check" class="size-4 text-brand-400" />{{ $v }}</span>
                    @endforeach
                </div>
            </div>
            <div>
                <h3 class="mb-5 text-xl font-bold">{{ __('What makes us different') }}</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($whyUs as $w)
                        <div class="flex items-center gap-3 rounded-2xl bg-white/[0.04] p-4 ring-1 ring-white/10">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-ink-900"><x-icon :name="$w['icon']" class="size-5" /></span>
                            <span class="font-semibold">{{ $w['title'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Booking --}}
    <section id="book" class="site-container scroll-mt-24 py-20">
        <div class="grid overflow-hidden rounded-[2rem] bg-brand-50 ring-1 ring-brand-100 lg:grid-cols-5">
            <div class="p-8 sm:p-10 lg:col-span-2">
                <p class="section-kicker">{{ __('Free consultation') }}</p>
                <h2 class="section-title">{{ __('Book your eye exam') }}</h2>
                <p class="mt-4 text-slate-600">{{ __('Leave your number and choose a branch and a day. We will call you to confirm the appointment.') }}</p>
                <ul class="mt-6 space-y-3 text-sm text-ink-800">
                    <li class="flex items-center gap-2"><x-icon name="check" class="size-5 text-brand-600" />{{ __('Computerised eye exam') }}</li>
                    <li class="flex items-center gap-2"><x-icon name="check" class="size-5 text-brand-600" />{{ __('Advice on the right lenses and frames') }}</li>
                    <li class="flex items-center gap-2"><x-icon name="check" class="size-5 text-brand-600" />{{ __('No waiting at the branch') }}</li>
                </ul>
                @if ($examWhatsapp)
                    <a href="{{ $examWhatsapp }}" target="_blank" rel="noopener" class="mt-8 inline-flex items-center gap-2 font-semibold text-emerald-700 hover:underline"><x-icon name="chat" class="size-5" />{{ __('Or book on WhatsApp') }}</a>
                @endif
            </div>
            <div class="bg-white p-8 sm:p-10 lg:col-span-3">
                @include('storefront.partials.inquiry-form', ['site' => $site, 'product' => null, 'defaultType' => 'exam'])
            </div>
        </div>
    </section>

    {{-- Branches --}}
    @if ($site->branches()->isNotEmpty())
        <section id="branches" class="site-container scroll-mt-24 pb-4">
            <div class="section-head">
                <div><p class="section-kicker">{{ __('Visit us') }}</p><h2 class="section-title">{{ __('Our branches') }}</h2></div>
                @if ($site->get('hours'))<p class="flex items-center gap-2 text-sm text-slate-600"><x-icon name="clock" class="size-5 text-brand-600" />{{ $site->get('hours') }}</p>@endif
            </div>
            <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($site->branches() as $b)
                    <div class="flex flex-col rounded-3xl border border-slate-200 p-6">
                        <div class="flex items-center gap-3">
                            <span class="flex size-11 items-center justify-center rounded-2xl bg-ink-900 text-brand-400"><x-icon name="map-pin" class="size-5" /></span>
                            <h3 class="text-lg font-bold text-ink-900">{{ $b->displayName() }}</h3>
                        </div>
                        @if ($b->address || $b->city)<p class="mt-4 text-slate-600">{{ collect([$b->address, $b->city])->filter()->join(' — ') }}</p>@endif
                        <div class="mt-auto flex flex-wrap gap-2 pt-5">
                            @if ($b->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $b->phone) }}" class="btn-secondary btn-sm rounded-full"><x-icon name="phone" class="size-4" /><span dir="ltr">{{ $b->phone }}</span></a>@endif
                            @if ($map = \App\Modules\Website\Support\SiteInfo::mapUrl($b))<a href="{{ $map }}" target="_blank" rel="noopener" class="btn-secondary btn-sm rounded-full"><x-icon name="map-pin" class="size-4" />{{ __('Directions') }}</a>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endsection
