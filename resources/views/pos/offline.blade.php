<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#fe801f">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/favicon.png') }}">
    <title>{{ __('Offline POS') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/offline-pos.js'])
    <style>@media print { body > *:not(#receipt) { display: none !important; } #receipt { display: block !important; width: 72mm; font-size: 11px; } }</style>
</head>
<body class="bg-slate-50 p-4">
    <div class="no-print mb-4 flex items-center gap-3">
        <img src="{{ asset('images/brand/logo.png') }}" alt="" class="h-8 w-auto">
        <h1 class="text-xl font-bold">{{ __('Offline POS') }}</h1>
        <a href="{{ route('dashboard') }}" class="ms-auto text-sm text-brand-700 hover:underline">{{ __('Back to the ERP') }}</a>
    </div>
    <div id="offline-pos" class="no-print"
         data-registered="{{ $registered ? '1' : '0' }}"
         data-branches="{{ json_encode($branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->displayName()])->values()) }}"
         data-urls="{{ json_encode(['bootstrap' => route('pos.offline.bootstrap'), 'sync' => route('pos.offline.sync'), 'register' => route('pos.offline.register'), 'login' => route('login')]) }}"
         data-i18n="{{ json_encode([
            'online' => __('Online'), 'offline' => __('Offline'), 'catalog_of' => __('Catalog snapshot:'), 'sync_now' => __('Sync now'), 'syncing' => __('Syncing...'),
            'refresh' => __('Refresh catalog'), 'reauth' => __('Your session expired. Sign in again to sync; queued sales are kept safely.'), 'sign_in' => __('Sign in'),
            'device_problem' => __('This device is not registered for offline sales, or it was revoked.'),
            'scan' => __('Scan barcode / QR / SKU'), 'search' => __('Search product'), 'stock' => __('Stock'), 'low_snapshot' => __('Above the last known stock'),
            'empty' => __('Scan a barcode or search to add products.'), 'walk_in' => __('Walk-in customer'), 'subtotal' => __('Subtotal'), 'discount' => __('Invoice discount'),
            'tax' => __('Tax'), 'total' => __('Total'), 'cash' => __('Cash'), 'card' => __('Card'), 'tendered' => __('Tendered'), 'remaining' => __('Remaining'),
            'change' => __('Change'), 'complete' => __('Complete sale'), 'queue' => __('Offline sales queue'), 'no_queue' => __('No offline sales yet.'), 'retry' => __('Retry'),
            'st_pending' => __('Pending synchronization'), 'st_synced' => __('Synced'), 'st_failed' => __('Failed synchronization'), 'st_review' => __('Requires review'),
            'not_found' => __('No product found for :code.'), 'invalid_amount' => __('Invalid amount.'), 'overpaid' => __('Payments exceed the invoice total.'),
            'need_customer' => __('Select a registered customer for credit or partial-payment sales.'), 'no_discount' => __('You are not allowed to give discounts.'),
            'discount_limit' => __('Discount exceeds your limit.'), 'saved' => __('Sale saved on this device.'),
            'offline_receipt' => __('Offline receipt'), 'receipt_note' => __('The tax invoice number is issued after synchronization.'),
            'register_title' => __('Register this device for offline sales'), 'register_help' => __('The device is bound to one branch. Registration needs a connection; selling does not.'),
            'device_name' => __('Device name'), 'register' => __('Register device'), 'need_online' => __('Connect to the internet to register this device.'),
         ]) }}">
        <p class="text-sm text-slate-500">{{ __('Loading...') }}</p>
    </div>
    <div id="receipt" class="hidden"></div>
</body>
</html>
