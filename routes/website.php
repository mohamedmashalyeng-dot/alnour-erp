<?php

/*
| Public website (product showcase). No login. Served at the root of WEBSITE_DOMAIN when it is set,
| otherwise under /{WEBSITE_PREFIX} (default /shop) on the ERP domain.
*/

use App\Modules\Website\Http\StorefrontController;
use Illuminate\Support\Facades\Route;

$domain = config('erp.website.domain');
$group = $domain ? Route::domain($domain) : Route::prefix(config('erp.website.prefix', 'shop'));

$group->name('shop.')->group(function () {
    Route::get('/', [StorefrontController::class, 'home'])->name('home');
    Route::get('/products', [StorefrontController::class, 'products'])->name('products');
    Route::get('/products/{product}/{slug?}', [StorefrontController::class, 'product'])->whereNumber('product')->name('product');
    Route::post('/inquiries', [StorefrontController::class, 'inquiry'])->middleware('throttle:5,1')->name('inquiry');
    Route::get('/sitemap.xml', [StorefrontController::class, 'sitemap'])->name('sitemap');
});
