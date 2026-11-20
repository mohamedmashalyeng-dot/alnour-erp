@php
    $branchAccess = app(\App\Modules\Branches\Services\BranchAccess::class);
    $user = auth()->user();
    $allowedBranches = $branchAccess->allowedBranches();
    $currentBranch = $branchAccess->current();
    $isRtl = app()->getLocale() === 'ar';
    $companyName = app(\App\Modules\Core\Services\Settings::class)->get('company_name');
    $nav = \App\Modules\Core\Support\Navigation::for($user);

    // Client-side menu state: what exists, what is hidden, which page is open.
    $navConfig = [
        'hidden' => collect($nav)->flatMap(fn ($g) => $g['items'])->where('hidden', true)->pluck('route')->values(),
        'active' => collect($nav)->flatMap(fn ($g) => $g['items'])->firstWhere('active', true)['route'] ?? null,
        'sections' => collect($nav)->mapWithKeys(fn ($g) => [$g['key'] => collect($g['items'])->map(fn ($i) => [
            'key' => $i['route'],
            'label' => __($i['label']),
            'search' => __($i['label']).' '.$i['label'].' '.($g['section'] ? __($g['section']).' '.$g['section'] : ''),
            'url' => route($i['route']),
        ])->values()]),
        'urls' => ['pages' => route('preferences.pages'), 'showAll' => route('preferences.pages.show-all')],
        'text' => [
            'hidden' => __('Hidden from the menu: :page'),
            'shown' => __('Back in the menu: :page'),
            'undo' => __('Undo'),
            'error' => __('Could not save. Please try again.'),
            'allShown' => __('All pages are visible again.'),
        ],
    ];
    $initials = collect(preg_split('/\s+/u', trim($user->name)))->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join('');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? __($title).' — ' : '' }}{{ $companyName }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/brand/favicon.png') }}">
    @fonts
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#fe801f">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen" x-data="{ sidebar: false }" @keydown.escape.window="sidebar = false" @open-sidebar.window="sidebar = true">
<a href="#main" class="skip-link">{{ __('Skip to content') }}</a>
<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <div x-show="sidebar" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/50 backdrop-blur-sm lg:hidden no-print" @click="sidebar = false"></div>
    <aside class="sidebar no-print fixed inset-y-0 start-0 z-40 flex w-72 flex-col bg-ink-900 transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:w-64 lg:translate-x-0"
           :class="sidebar ? 'translate-x-0' : '{{ $isRtl ? 'translate-x-full' : '-translate-x-full' }} lg:translate-x-0'"
           x-data="erpNav(@js($navConfig))" @nav-edit.window="startEditing()" @keydown.window="shortcut($event)">

        <div class="flex h-16 shrink-0 items-center gap-2.5 px-4">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2.5">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-white/5 ring-1 ring-white/10">
                    <img src="{{ asset('images/brand/icon.png') }}" alt="" class="h-5 w-auto">
                </span>
                <span class="truncate font-bold text-white">{{ $companyName }}</span>
            </a>
            <button class="ms-auto rounded-lg p-1.5 text-slate-400 hover:bg-white/10 hover:text-white lg:hidden" @click="sidebar = false" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
        </div>

        {{-- Menu search --}}
        <div class="px-3 pb-2" x-show="!editing">
            <label class="relative block">
                <span class="sr-only">{{ __('Search the menu') }}</span>
                <x-icon name="search" class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-slate-500" />
                <input type="search" x-ref="search" x-model="query" @keydown.enter.prevent="openFirst()" @keydown.escape="query = ''; $el.blur()"
                       placeholder="{{ __('Search the menu') }}" autocomplete="off"
                       class="w-full rounded-xl border-0 bg-white/5 py-2 ps-9 pe-10 text-sm text-white ring-1 ring-white/10 placeholder:text-slate-500 focus:bg-white/10 focus:ring-2 focus:ring-brand-500 focus:outline-none">
                <kbd class="pointer-events-none absolute inset-y-0 end-2.5 my-auto hidden h-5 items-center rounded border border-white/15 px-1.5 text-[10px] text-slate-500 lg:flex" x-show="!query">/</kbd>
            </label>
        </div>

        {{-- Editing banner --}}
        <div class="mx-3 mb-2 rounded-xl bg-brand-500/10 p-3 text-xs leading-relaxed text-brand-200 ring-1 ring-brand-500/30" x-show="editing" x-cloak>
            <div class="mb-1 flex items-center gap-1.5 font-semibold text-brand-300"><x-icon name="adjustments" class="size-4" /> {{ __('Customize menu') }}</div>
            {{ __('Turn off the pages you do not use. They disappear from the menu only — you can bring them back here at any time.') }}
        </div>

        <nav class="sidebar-scroll flex-1 overflow-y-auto px-3 pb-4" aria-label="{{ __('Main menu') }}">
            @foreach ($nav as $group)
                <div class="nav-group" x-show="sectionVisible('{{ $group['key'] }}')" @if (collect($group['items'])->every(fn ($i) => $i['hidden'] && ! $i['active'])) x-cloak @endif>
                    @if ($group['section'])
                        <div class="nav-section">
                            <button type="button" class="flex flex-1 items-center gap-1 text-start hover:text-slate-200" @click="toggleSection('{{ $group['key'] }}')"
                                    :aria-expanded="isOpen('{{ $group['key'] }}')">
                                <span>{{ __($group['section']) }}</span>
                                <x-icon name="chevron-down" class="size-3 transition-transform" ::class="isOpen('{{ $group['key'] }}') ? '' : '{{ $isRtl ? 'rotate-90' : '-rotate-90' }}'" />
                            </button>
                            <button type="button" x-show="editing" x-cloak class="rounded px-1.5 py-0.5 text-[10px] font-medium normal-case tracking-normal text-slate-400 hover:bg-white/10 hover:text-white"
                                    @click="toggleSectionPages('{{ $group['key'] }}')"
                                    x-text="sectionAllHidden('{{ $group['key'] }}') ? @js(__('Show all')) : @js(__('Hide all'))"></button>
                        </div>
                    @endif
                    <div x-show="isOpen('{{ $group['key'] }}')" x-collapse.duration.150ms>
                        @foreach ($group['items'] as $item)
                            <div class="nav-item group" x-show="visible('{{ $item['route'] }}')" @if ($item['hidden'] && ! $item['active']) x-cloak @endif>
                                <a href="{{ route($item['route']) }}" @class(['nav-link', 'nav-link-active' => $item['active']])
                                   :class="isHidden('{{ $item['route'] }}') && active !== '{{ $item['route'] }}' ? 'opacity-40' : ''"
                                   @if ($item['active']) aria-current="page" @endif
                                   @if (! empty($item['external'])) target="_blank" rel="noopener" @endif>
                                    <x-icon :name="$item['icon']" class="size-5 shrink-0" />
                                    <span class="truncate">{{ __($item['label']) }}</span>
                                </a>
                                {{-- Edit mode: a clear on/off switch. Normal mode: a small "hide" button on hover. --}}
                                <button type="button" x-show="editing" x-cloak role="switch" class="nav-switch"
                                        :aria-checked="!isHidden('{{ $item['route'] }}')" @click="toggle('{{ $item['route'] }}')"
                                        aria-label="{{ __('Show in menu') }}: {{ __($item['label']) }}"><span></span></button>
                                <button type="button" x-show="!editing && !isHidden('{{ $item['route'] }}')" class="nav-hide" @click.prevent="toggle('{{ $item['route'] }}')"
                                        title="{{ __('Hide from menu') }}" aria-label="{{ __('Hide from menu') }}: {{ __($item['label']) }}">
                                    <x-icon name="eye-slash" class="size-4" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <p class="px-3 py-6 text-center text-sm text-slate-500" x-show="query && !anyMatch()" x-cloak>{{ __('No pages match your search.') }}</p>
        </nav>

        {{-- Footer: customize / done --}}
        <div class="shrink-0 border-t border-white/10 p-3">
            <template x-if="!editing">
                <button type="button" class="nav-link w-full" @click="startEditing()">
                    <x-icon name="adjustments" class="size-5 shrink-0" />
                    <span class="flex-1 text-start">{{ __('Customize menu') }}</span>
                    <span x-show="hidden.length" x-cloak class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-slate-300"
                          x-text="@js(__(':count hidden')).replace(':count', hidden.length)"></span>
                </button>
            </template>
            <template x-if="editing">
                <div class="flex gap-2">
                    <button type="button" class="btn flex-1 bg-white/10 text-white hover:bg-white/15" @click="showAll()" :disabled="!hidden.length">{{ __('Show all') }}</button>
                    <button type="button" class="btn-primary flex-1" @click="editing = false"><x-icon name="check" class="size-4" /> {{ __('Done') }}</button>
                </div>
            </template>
        </div>
    </aside>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="no-print sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur sm:gap-3 sm:px-6">
            <button class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="sidebar = true" aria-label="{{ __('Menu') }}"><x-icon name="bars" class="size-6" /></button>

            <form method="POST" action="{{ route('branch.switch') }}" class="flex min-w-0 items-center">
                @csrf
                <label for="branch-switcher" class="sr-only">{{ __('Branch') }}</label>
                <div class="relative">
                    <x-icon name="building" class="pointer-events-none absolute inset-y-0 start-2.5 my-auto size-4 text-slate-400" />
                    <select id="branch-switcher" name="branch_id" onchange="this.form.submit()"
                            class="form-input max-w-[11rem] rounded-full border-slate-200 bg-slate-50 py-1.5 ps-8 text-sm font-medium sm:max-w-[14rem]">
                        @if ($user->canAccessAllBranches())
                            <option value="0" @selected($currentBranch === 0)>{{ __('All branches') }}</option>
                        @endif
                        @foreach ($allowedBranches as $b)
                            <option value="{{ $b->id }}" @selected($currentBranch === $b->id)>{{ $b->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <noscript><button class="btn-secondary btn-sm ms-2">{{ __('Switch') }}</button></noscript>
            </form>

            <div class="ms-auto flex items-center gap-1 sm:gap-2">
                @if (\Illuminate\Support\Facades\Route::has('pos') && $user->can('sales.create'))
                    <a href="{{ route('pos') }}" class="btn-primary hidden sm:inline-flex"><x-icon name="cart" class="size-4" /> {{ __('New sale') }}</a>
                @endif
                <a href="{{ route('locale', $isRtl ? 'en' : 'ar') }}" class="rounded-lg px-2.5 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-brand-700">{{ $isRtl ? 'EN' : 'ع' }}</a>

                {{-- User menu --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                    <button type="button" class="flex items-center gap-2 rounded-full p-1 pe-2 hover:bg-slate-100" @click="open = !open" :aria-expanded="open" aria-haspopup="true">
                        <span class="flex size-8 items-center justify-center rounded-full bg-brand-500 text-sm font-bold text-ink-900">{{ $initials }}</span>
                        <span class="hidden max-w-[9rem] truncate text-sm font-semibold text-slate-700 md:block">{{ $user->name }}</span>
                        <x-icon name="chevron-down" class="hidden size-4 text-slate-400 md:block" />
                    </button>
                    <div x-show="open" x-cloak x-transition.origin.top class="absolute end-0 mt-2 w-64 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <div class="truncate text-sm font-semibold text-slate-900">{{ $user->name }}</div>
                            <div class="truncate text-xs text-slate-500">{{ $user->getRoleNames()->map(fn ($r) => __('role.'.$r))->join('، ') }}</div>
                        </div>
                        <div class="p-1.5">
                            <button type="button" class="menu-item" @click="open = false; sidebar = true; $dispatch('nav-edit')">
                                <x-icon name="adjustments" class="size-5 text-slate-400" /> {{ __('Customize menu') }}
                            </button>
                            <a href="{{ route('locale', $isRtl ? 'en' : 'ar') }}" class="menu-item">
                                <span class="flex size-5 items-center justify-center text-xs font-bold text-slate-400">{{ $isRtl ? 'EN' : 'ع' }}</span> {{ $isRtl ? 'English' : 'العربية' }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="menu-item text-rose-600 hover:bg-rose-50"><x-icon name="logout" class="size-5" /> {{ __('Sign out') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main id="main" class="mx-auto w-full max-w-[1600px] flex-1 p-4 sm:p-6">
            @if (session('success'))
                <div class="alert alert-success" x-data="{ show: true }" x-show="show" x-transition>
                    <x-icon name="check" class="size-5 shrink-0" />
                    <span class="flex-1">{{ session('success') }}</span>
                    <button type="button" class="opacity-60 hover:opacity-100" @click="show = false" aria-label="{{ __('Close') }}"><x-icon name="x" class="size-4" /></button>
                </div>
            @endif
            @if (session('error'))
                <div class="alert alert-error" x-data="{ show: true }" x-show="show" x-transition>
                    <x-icon name="alert" class="size-5 shrink-0" />
                    <span class="flex-1">{{ session('error') }}</span>
                    <button type="button" class="opacity-60 hover:opacity-100" @click="show = false" aria-label="{{ __('Close') }}"><x-icon name="x" class="size-4" /></button>
                </div>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>

{{-- Toasts. From Livewire: $this->dispatch('toast', message: '...', type: 'success').
     From JS: window.dispatchEvent(new CustomEvent('toast', { detail: { message, type, actionLabel, action } })) --}}
<div x-data="{ toasts: [], remove(id) { this.toasts = this.toasts.filter(x => x.id !== id) } }"
     @toast.window="const t = { id: Date.now() + Math.random(), message: $event.detail.message, type: $event.detail.type || 'success', actionLabel: $event.detail.actionLabel || null, action: $event.detail.action || null }; toasts.push(t); setTimeout(() => remove(t.id), t.actionLabel ? 6000 : 4000)"
     class="no-print pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-center gap-2 sm:inset-x-auto sm:end-4 sm:items-end" aria-live="polite">
    <template x-for="t in toasts" :key="t.id">
        <div x-transition class="pointer-events-auto flex w-full max-w-sm items-center gap-3 rounded-xl px-4 py-3 text-sm text-white shadow-lg"
             :class="t.type === 'error' ? 'bg-rose-600' : 'bg-ink-800'">
            <span class="size-2 shrink-0 rounded-full" :class="t.type === 'error' ? 'bg-white' : 'bg-emerald-400'"></span>
            <span class="flex-1" x-text="t.message"></span>
            <button type="button" x-show="t.actionLabel" class="shrink-0 font-semibold text-brand-400 hover:text-brand-300" x-text="t.actionLabel" @click="t.action(); remove(t.id)"></button>
        </div>
    </template>
</div>
</body>
</html>
