<div>
    <x-page-header :title="__('Dashboard')" :subtitle="__('Welcome, :name', ['name' => auth()->user()->name])" />

    @php
        // Shortcuts to the everyday tasks. A shortcut disappears if the user cannot open the page
        // or has hidden its page from the menu.
        $user = auth()->user();
        $quick = collect([
            ['route' => 'pos', 'nav' => 'pos', 'can' => 'sales.create', 'icon' => 'cart', 'label' => 'New sale', 'tone' => 'bg-brand-500 text-ink-900'],
            ['route' => 'optical.prescriptions', 'nav' => 'optical.prescriptions', 'can' => 'prescriptions.view', 'icon' => 'eyeglasses', 'label' => 'Prescriptions', 'tone' => 'bg-sky-100 text-sky-700'],
            ['route' => 'optical.repairs', 'nav' => 'optical.repairs', 'can' => 'repairs.view', 'icon' => 'wrench', 'label' => 'Repairs', 'tone' => 'bg-violet-100 text-violet-700'],
            ['route' => 'returns.create', 'nav' => 'returns.index', 'can' => 'returns.create', 'icon' => 'return', 'label' => 'New return', 'tone' => 'bg-amber-100 text-amber-700'],
            ['route' => 'customers.index', 'nav' => 'customers.index', 'can' => 'customers.view', 'icon' => 'user', 'label' => 'Customers', 'tone' => 'bg-emerald-100 text-emerald-700'],
            ['route' => 'products.create', 'nav' => 'products.index', 'can' => 'products.create', 'icon' => 'cube', 'label' => 'Add product', 'tone' => 'bg-slate-100 text-slate-700'],
            ['route' => 'inventory.operations', 'nav' => 'inventory.operations', 'canany' => ['inventory.opening-stock', 'inventory.receive', 'inventory.adjust'], 'icon' => 'archive', 'label' => 'Receive stock', 'tone' => 'bg-teal-100 text-teal-700'],
            ['route' => 'purchases.create', 'nav' => 'purchases.index', 'can' => 'purchases.create', 'icon' => 'truck', 'label' => 'New purchase order', 'tone' => 'bg-indigo-100 text-indigo-700'],
            ['route' => 'expenses.create', 'nav' => 'expenses.index', 'can' => 'expenses.create', 'icon' => 'receipt', 'label' => 'New expense', 'tone' => 'bg-rose-100 text-rose-700'],
        ])->filter(fn ($a) => \Illuminate\Support\Facades\Route::has($a['route'])
            && (isset($a['canany']) ? $user->canany($a['canany']) : $user->can($a['can']))
            && ! \App\Modules\Core\Support\Navigation::isHidden($user, $a['nav']))->take(8);
    @endphp

    @if ($user->can('website.inquiries') && \Illuminate\Support\Facades\Route::has('website.inquiries'))
        @php $newRequests = app(\App\Modules\Website\Services\InquiryService::class)->visibleQuery($user)->where('status', 'new')->count(); @endphp
        @if ($newRequests > 0)
            <a href="{{ route('website.inquiries') }}" class="mb-6 flex items-center gap-4 rounded-2xl border border-brand-200 bg-brand-50 p-4 transition hover:border-brand-300 hover:bg-brand-100/60">
                <span class="relative flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-ink-900">
                    <x-icon name="chat" class="size-6" />
                    <span class="absolute -end-1 -top-1 flex size-5 items-center justify-center rounded-full bg-rose-600 text-[11px] font-bold text-white">{{ $newRequests }}</span>
                </span>
                <span class="flex-1">
                    <span class="block font-semibold text-ink-900">{{ trans_choice('{1} One new request from the website|[2,*] :count new requests from the website', $newRequests, ['count' => $newRequests]) }}</span>
                    <span class="block text-sm text-slate-600">{{ __('Orders and eye-exam bookings waiting for a call.') }}</span>
                </span>
                <x-icon name="{{ app()->getLocale() === 'ar' ? 'chevron-left' : 'chevron-right' }}" class="size-5 text-brand-700" />
            </a>
        @endif
    @endif

    @if ($quick->isNotEmpty())
        <section class="mb-6" aria-labelledby="quick-actions">
            <h2 id="quick-actions" class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-500"><x-icon name="bolt" class="size-4 text-brand-600" /> {{ __('Quick actions') }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
                @foreach ($quick as $a)
                    <a href="{{ route($a['route']) }}" class="card group flex flex-col items-center gap-2.5 px-2 py-4 text-center transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md">
                        <span class="flex size-11 items-center justify-center rounded-2xl {{ $a['tone'] }} transition group-hover:scale-105"><x-icon :name="$a['icon']" class="size-6" /></span>
                        <span class="text-sm font-semibold text-slate-700">{{ __($a['label']) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($sales)
        <div class="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat :label="__('Sales today')" :value="money($sales['net_sales'])" icon="cash" tone="emerald" />
            <x-stat :label="__('Invoices today')" :value="number_format($sales['invoices'])" icon="receipt" />
            <x-stat :label="__('Collected today')" :value="money($sales['collected'])" icon="cash" tone="sky" />
            <x-stat :label="__('Outstanding receivables')" :value="money($sales['receivables'])" icon="alert" tone="amber" />
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('Active products')" :value="number_format($stats['products'])" icon="cube" :href="auth()->user()->can('products.view') ? route('products.index') : null" />
        <x-stat :label="__('Units in stock')" :value="number_format($stats['units'])" icon="archive" tone="emerald" :href="auth()->user()->can('inventory.view') ? route('inventory.index') : null" />
        <x-stat :label="__('Low-stock alerts')" :value="number_format($stats['low'])" icon="alert" tone="rose" :href="auth()->user()->can('inventory.view') ? route('inventory.index', ['lowOnly' => 1]) : null" />
        <x-stat :label="__('Open transfers')" :value="number_format($stats['transfers'])" icon="arrows" tone="amber" :href="auth()->user()->can('transfers.view') ? route('transfers.index') : null" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <div class="card xl:col-span-1">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Stock by branch') }}</div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Branch') }}</th><th>{{ __('Units') }}</th><th>{{ __('Low stock') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($byBranch as $row)
                        <tr>
                            <td>{{ app()->getLocale() === 'en' && $row->name_en ? $row->name_en : $row->name }}</td>
                            <td>{{ number_format($row->units) }}</td>
                            <td>@if ($row->low > 0)<x-status-badge color="rose">{{ $row->low }}</x-status-badge>@else — @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-slate-500">{{ __('No data yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card xl:col-span-2">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Low-stock alerts') }}</div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Minimum') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($lowStock as $row)
                        <tr>
                            <td><div class="font-medium">{{ $row->product->displayName() }}</div><div class="text-xs text-slate-500" dir="ltr">{{ $row->product->sku }}</div></td>
                            <td>{{ $row->branch->displayName() }}</td>
                            <td class="font-semibold text-rose-600">{{ $row->quantity }}</td>
                            <td>{{ $row->min_stock_level }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-slate-500">{{ __('No low-stock alerts.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($recent->isNotEmpty())
        <div class="card mt-6">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <span class="font-semibold">{{ __('Recent stock movements') }}</span>
                <a href="{{ route('inventory.movements') }}" class="text-sm text-brand-700 hover:underline">{{ __('View all') }}</a>
            </div>
            @include('livewire.inventory.partials.movements-table', ['movements' => $recent])
        </div>
    @endif
</div>
