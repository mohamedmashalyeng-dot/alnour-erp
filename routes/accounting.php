<?php

/*
|--------------------------------------------------------------------------
| Phase 3 routes: accounting, cash & bank, expenses, payroll.
| Loaded inside the authenticated group of routes/web.php.
|--------------------------------------------------------------------------
*/

use App\Modules\Accounting\Livewire\AgingReport;
use App\Modules\Accounting\Livewire\BalanceSheet;
use App\Modules\Accounting\Livewire\CashFlow;
use App\Modules\Accounting\Livewire\ChartOfAccounts;
use App\Modules\Accounting\Livewire\FinancialDashboard;
use App\Modules\Accounting\Livewire\GeneralLedger;
use App\Modules\Accounting\Livewire\IncomeStatement;
use App\Modules\Accounting\Livewire\JournalForm;
use App\Modules\Accounting\Livewire\JournalIndex;
use App\Modules\Accounting\Livewire\JournalShow;
use App\Modules\Accounting\Livewire\PeriodIndex;
use App\Modules\Accounting\Livewire\RevenueReport;
use App\Modules\Accounting\Livewire\TrialBalance;
use App\Modules\Accounting\Livewire\VatReport;
use App\Modules\Expenses\Http\AttachmentController;
use App\Modules\Expenses\Livewire\ExpenseForm;
use App\Modules\Expenses\Livewire\ExpenseIndex;
use App\Modules\Expenses\Livewire\ExpenseShow;
use App\Modules\Payroll\Livewire\EmployeeIndex;
use App\Modules\Payroll\Livewire\PayrollIndex;
use App\Modules\Payroll\Livewire\PayrollShow;
use App\Modules\Treasury\Livewire\BankIndex;
use App\Modules\Treasury\Livewire\BankReconcile;
use App\Modules\Treasury\Livewire\CashIndex;
use Illuminate\Support\Facades\Route;

Route::prefix('accounting')->name('accounting.')->group(function () {
    Route::get('/', FinancialDashboard::class)->name('dashboard');
    Route::get('/accounts', ChartOfAccounts::class)->name('accounts');
    Route::get('/journal', JournalIndex::class)->name('journal');
    Route::get('/journal/create', JournalForm::class)->name('journal.create');
    Route::get('/journal/{entry}', JournalShow::class)->name('journal.show');
    Route::get('/periods', PeriodIndex::class)->name('periods');
    Route::get('/reports/trial-balance', TrialBalance::class)->name('trial-balance');
    Route::get('/reports/ledger', GeneralLedger::class)->name('ledger');
    Route::get('/reports/income-statement', IncomeStatement::class)->name('income-statement');
    Route::get('/reports/balance-sheet', BalanceSheet::class)->name('balance-sheet');
    Route::get('/reports/cash-flow', CashFlow::class)->name('cash-flow');
    Route::get('/reports/vat', VatReport::class)->name('vat');
    Route::get('/reports/aging', AgingReport::class)->name('aging');
    Route::get('/reports/revenue', RevenueReport::class)->name('revenue');
});

Route::get('/cash', CashIndex::class)->name('cash.index');
Route::get('/bank', BankIndex::class)->name('bank.index');
Route::get('/bank/{bankAccount}/reconcile', BankReconcile::class)->name('bank.reconcile');

Route::get('/expenses', ExpenseIndex::class)->name('expenses.index');
Route::get('/expenses/create', ExpenseForm::class)->name('expenses.create');
Route::get('/expenses/{expense}', ExpenseShow::class)->name('expenses.show');
Route::get('/expenses/{expense}/edit', ExpenseForm::class)->name('expenses.edit');
Route::get('/attachments/{attachment}', AttachmentController::class)->middleware('throttle:sensitive')->name('attachments.show');

Route::get('/payroll', PayrollIndex::class)->name('payroll.index');
Route::get('/payroll/employees', EmployeeIndex::class)->name('payroll.employees');
Route::get('/payroll/{run}', PayrollShow::class)->name('payroll.show');
