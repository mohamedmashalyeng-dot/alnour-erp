<div>
    <x-page-header :title="__('Website settings')" :subtitle="__('Texts and contact details shown on the website. Branches, addresses and branch phones come from the Branches page.')">
        <x-slot:actions>
            <a href="{{ route('shop.home') }}" target="_blank" rel="noopener" class="btn-secondary"><x-icon name="eye" class="size-4" /> {{ __('View website') }}</a>
        </x-slot:actions>
    </x-page-header>

    <form wire:submit="save" class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card card-body space-y-4">
                <h2 class="font-semibold">{{ __('Home page texts') }}</h2>
                <div>
                    <label class="form-label" for="ws-title">{{ __('Main headline') }}</label>
                    <input id="ws-title" wire:model="form.website_hero_title" class="form-input">
                    @error('form.website_hero_title') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label" for="ws-sub">{{ __('Sub-headline') }}</label>
                    <input id="ws-sub" wire:model="form.website_hero_subtitle" class="form-input">
                    @error('form.website_hero_subtitle') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label" for="ws-about">{{ __('About us') }}</label>
                    <textarea id="ws-about" wire:model="form.website_about" rows="5" class="form-input"></textarea>
                    @error('form.website_about') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="ws-tag">{{ __('Footer slogan') }}</label>
                        <input id="ws-tag" wire:model="form.website_tagline" class="form-input">
                    </div>
                    <div>
                        <label class="form-label" for="ws-hours">{{ __('Working hours') }}</label>
                        <input id="ws-hours" wire:model="form.website_hours" class="form-input">
                    </div>
                </div>
            </section>

            <section class="card card-body space-y-4">
                <h2 class="font-semibold">{{ __('Contact') }}</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="form-label" for="ws-phone">{{ __('Phone') }}</label>
                        <input id="ws-phone" wire:model="form.website_phone" class="form-input" dir="ltr" placeholder="01xxxxxxxxx">
                        @error('form.website_phone') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="form-label" for="ws-wa">{{ __('WhatsApp number') }}</label>
                        <input id="ws-wa" wire:model="form.website_whatsapp" class="form-input" dir="ltr" placeholder="01xxxxxxxxx">
                        @error('form.website_whatsapp') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="form-label" for="ws-cc">{{ __('Country code') }}</label>
                        <input id="ws-cc" wire:model="form.website_whatsapp_country_code" class="form-input" dir="ltr" placeholder="20">
                        @error('form.website_whatsapp_country_code') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-xs text-slate-500">{{ __('Leave WhatsApp empty to hide the WhatsApp buttons. If the phone is empty, the first branch phone is shown.') }}</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach (['website_facebook' => 'Facebook', 'website_instagram' => 'Instagram', 'website_tiktok' => 'TikTok'] as $key => $label)
                        <div>
                            <label class="form-label" for="ws-{{ $key }}">{{ $label }}</label>
                            <input id="ws-{{ $key }}" wire:model="form.{{ $key }}" class="form-input" dir="ltr" placeholder="https://">
                            @error('form.'.$key) <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </section>
        </div>

        <div class="space-y-6">
            <section class="card card-body space-y-4">
                <h2 class="font-semibold">{{ __('Display') }}</h2>
                @foreach ([
                    'website_enabled' => [__('Website is online'), __('When off, visitors get "page not found". You can still preview it.')],
                    'website_show_prices' => [__('Show prices'), __('Prices come from the product selling price.')],
                    'website_show_stock' => [__('Show availability'), __('Shows "available" and which branch has the product.')],
                ] as $key => [$label, $hint])
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="checkbox" wire:model="form.{{ $key }}" class="mt-1">
                        <span><span class="block text-sm font-semibold text-slate-800">{{ $label }}</span><span class="block text-xs text-slate-500">{{ $hint }}</span></span>
                    </label>
                @endforeach
            </section>

            <section class="card card-body space-y-2 text-sm text-slate-600">
                <h2 class="font-semibold text-slate-900">{{ __('Connected to the system') }}</h2>
                <p>{{ __('Products, photos, prices and stock are read live from the system.') }}</p>
                <ul class="space-y-1">
                    @can('website.manage')<li><a href="{{ route('website.products') }}" class="text-brand-700 hover:underline">{{ __('Choose website products') }}</a></li>@endcan
                    @can('branches.view')<li><a href="{{ route('branches.index') }}" class="text-brand-700 hover:underline">{{ __('Edit branches, addresses and phones') }}</a></li>@endcan
                    @can('website.inquiries')<li><a href="{{ route('website.inquiries') }}" class="text-brand-700 hover:underline">{{ __('Website requests') }}</a></li>@endcan
                </ul>
            </section>

            <button class="btn-primary w-full">{{ __('Save') }}</button>
        </div>
    </form>
</div>
