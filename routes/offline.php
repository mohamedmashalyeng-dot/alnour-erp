<?php

/*
| Phase 6 routes: offline-first POS (PWA) and synchronization review.
*/

use App\Modules\Offline\Http\OfflinePosController;
use App\Modules\Offline\Livewire\SyncReview;
use Illuminate\Support\Facades\Route;

Route::get('/pos/offline', [OfflinePosController::class, 'page'])->name('pos.offline');
Route::middleware('throttle:sensitive')->group(function () {
    Route::post('/pos/offline/devices', [OfflinePosController::class, 'register'])->name('pos.offline.register');
    Route::get('/pos/offline/bootstrap', [OfflinePosController::class, 'bootstrap'])->name('pos.offline.bootstrap');
    Route::post('/pos/offline/sync', [OfflinePosController::class, 'sync'])->name('pos.offline.sync');
});
Route::get('/pos/offline/ping', [OfflinePosController::class, 'ping'])->name('pos.offline.ping');
Route::get('/offline/review', SyncReview::class)->name('offline.review');
