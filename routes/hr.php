<?php

/*
| HR module routes: employee files, attendance, leave, performance and self-service.
| Every component authorizes itself (hr.view / hr.manage / hr.self); services re-check.
*/

use App\Modules\HR\Livewire\AttendanceBoard;
use App\Modules\HR\Livewire\EmployeeDirectory;
use App\Modules\HR\Livewire\EmployeeProfile;
use App\Modules\HR\Livewire\LeaveIndex;
use App\Modules\HR\Livewire\MyHr;
use App\Modules\HR\Livewire\PerformanceReport;
use Illuminate\Support\Facades\Route;

Route::prefix('hr')->name('hr.')->group(function () {
    Route::get('/employees', EmployeeDirectory::class)->name('employees');
    Route::get('/employees/{employee}', EmployeeProfile::class)->name('employees.show');
    Route::get('/attendance', AttendanceBoard::class)->name('attendance');
    Route::get('/leave', LeaveIndex::class)->name('leave');
    Route::get('/performance', PerformanceReport::class)->name('performance');
    Route::get('/me', MyHr::class)->name('me');
});
