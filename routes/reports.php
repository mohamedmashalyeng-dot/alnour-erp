<?php

/*
| Phase 4 routes: executive dashboard, report center, business insights (analytics / optional AI).
*/

use App\Modules\Reports\Http\ReportExportController;
use App\Modules\Reports\Livewire\BusinessInsights;
use App\Modules\Reports\Livewire\ExecutiveDashboard;
use App\Modules\Reports\Livewire\ReportCenter;
use Illuminate\Support\Facades\Route;

Route::get('/reports', ExecutiveDashboard::class)->name('reports.executive');
Route::get('/reports/center', ReportCenter::class)->name('reports.center');
Route::get('/reports/insights', BusinessInsights::class)->name('reports.insights');
Route::get('/reports/export', ReportExportController::class)->middleware('throttle:sensitive')->name('reports.export');
