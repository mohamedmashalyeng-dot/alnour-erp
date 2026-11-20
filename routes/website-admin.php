<?php

/*
| Dashboard side of the public website: requests, published products, site settings.
*/

use App\Modules\Website\Livewire\InquiryIndex;
use App\Modules\Website\Livewire\WebsiteProducts;
use App\Modules\Website\Livewire\WebsiteSettings;
use Illuminate\Support\Facades\Route;

Route::prefix('website')->name('website.')->group(function () {
    Route::get('/requests', InquiryIndex::class)->name('inquiries');
    Route::get('/products', WebsiteProducts::class)->name('products');
    Route::get('/settings', WebsiteSettings::class)->name('settings');
});
