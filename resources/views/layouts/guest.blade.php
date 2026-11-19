<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? __($title).' — ' : '' }}{{ app(\App\Modules\Core\Services\Settings::class)->get('company_name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/brand/favicon.png') }}">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="brand-backdrop min-h-screen">
    <div class="flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-md">
            {{ $slot }}
            <div class="mt-4 flex justify-center gap-3 text-sm text-white/80">
                <a href="{{ route('locale', 'ar') }}" class="hover:text-white">العربية</a>
                <span>|</span>
                <a href="{{ route('locale', 'en') }}" class="hover:text-white">English</a>
            </div>
        </div>
    </div>
</body>
</html>
