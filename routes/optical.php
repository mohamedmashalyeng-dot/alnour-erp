<?php

/*
| Eyewear module routes: prescriptions, warranties & claims, repairs, loyalty.
| Components authorize themselves; services re-check permissions and branch access.
*/

use App\Modules\Optical\Livewire\LoyaltyIndex;
use App\Modules\Optical\Livewire\PrescriptionIndex;
use App\Modules\Optical\Livewire\PrescriptionShow;
use App\Modules\Optical\Livewire\RepairIndex;
use App\Modules\Optical\Livewire\WarrantyIndex;
use Illuminate\Support\Facades\Route;

Route::prefix('optical')->name('optical.')->group(function () {
    Route::get('/prescriptions', PrescriptionIndex::class)->name('prescriptions');
    Route::get('/prescriptions/{prescription}', PrescriptionShow::class)->name('prescriptions.show');
    Route::get('/warranties', WarrantyIndex::class)->name('warranties');
    Route::get('/repairs', RepairIndex::class)->name('repairs');
    Route::get('/loyalty', LoyaltyIndex::class)->name('loyalty');
});
