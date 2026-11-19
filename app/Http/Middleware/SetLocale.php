<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('erp.locales', ['ar', 'en']);
        $locale = $request->session()->get('locale')
            ?? $request->user()?->locale
            ?? config('app.locale', 'ar');

        app()->setLocale(in_array($locale, $supported, true) ? $locale : 'ar');

        return $next($request);
    }
}
