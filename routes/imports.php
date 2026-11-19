<?php

/*
| Phase 5 routes: spreadsheet import/export (administrators only).
*/

use App\Modules\Imports\Http\ImportFileController;
use App\Modules\Imports\Livewire\ImportIndex;
use App\Modules\Imports\Livewire\ImportShow;
use Illuminate\Support\Facades\Route;

Route::get('/imports', ImportIndex::class)->name('imports.index');
Route::get('/imports/export', [ImportFileController::class, 'export'])->middleware('throttle:sensitive')->name('imports.export');
Route::get('/imports/{import}', ImportShow::class)->name('imports.show');
Route::get('/imports/{import}/report', [ImportFileController::class, 'report'])->name('imports.report');
