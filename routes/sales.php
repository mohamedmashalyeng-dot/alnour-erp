<?php

/*
|--------------------------------------------------------------------------
| Phase 2 routes: sales, POS, returns, customers, purchasing, payments.
| Loaded inside the authenticated group of routes/web.php.
|--------------------------------------------------------------------------
*/

use App\Modules\Customers\Livewire\CustomerIndex;
use App\Modules\Customers\Livewire\CustomerShow;
use App\Modules\Payments\Livewire\PaymentIndex;
use App\Modules\Purchasing\Http\PurchaseDocumentController;
use App\Modules\Purchasing\Livewire\PurchaseForm;
use App\Modules\Purchasing\Livewire\PurchaseIndex;
use App\Modules\Purchasing\Livewire\PurchaseShow;
use App\Modules\Sales\Http\SaleDocumentController;
use App\Modules\Sales\Http\StatementController;
use App\Modules\Sales\Livewire\Pos;
use App\Modules\Sales\Livewire\ReturnCreate;
use App\Modules\Sales\Livewire\ReturnIndex;
use App\Modules\Sales\Livewire\ReturnShow;
use App\Modules\Sales\Livewire\SaleIndex;
use App\Modules\Sales\Livewire\SalesDashboard;
use App\Modules\Sales\Livewire\SaleShow;
use App\Modules\Suppliers\Livewire\SupplierShow;
use Illuminate\Support\Facades\Route;

Route::get('/pos', Pos::class)->name('pos');
Route::get('/sales/dashboard', SalesDashboard::class)->name('sales.dashboard');
Route::get('/sales', SaleIndex::class)->name('sales.index');
Route::get('/sales/{sale}', SaleShow::class)->name('sales.show');

// Document generation is CPU-heavy on shared hosting: rate-limit it.
Route::middleware('throttle:sensitive')->group(function () {
    Route::get('/sales/{sale}/receipt', [SaleDocumentController::class, 'receipt'])->name('sales.receipt');
    Route::get('/sales/{sale}/invoice', [SaleDocumentController::class, 'invoice'])->name('sales.invoice');
    Route::get('/sales/{sale}/pdf', [SaleDocumentController::class, 'pdf'])->name('sales.pdf');
    Route::get('/purchases/{purchase}/pdf', [PurchaseDocumentController::class, 'pdf'])->name('purchases.pdf');
    Route::get('/customers/{customer}/statement', [StatementController::class, 'customer'])->name('customers.statement');
    Route::get('/suppliers/{supplier}/statement', [StatementController::class, 'supplier'])->name('suppliers.statement');
});

Route::get('/returns', ReturnIndex::class)->name('returns.index');
Route::get('/returns/create', ReturnCreate::class)->name('returns.create');
Route::get('/returns/{saleReturn}', ReturnShow::class)->name('returns.show');

Route::get('/customers', CustomerIndex::class)->name('customers.index');
Route::get('/customers/{customer}', CustomerShow::class)->name('customers.show');
Route::get('/suppliers/{supplier}', SupplierShow::class)->name('suppliers.show');

Route::get('/purchases', PurchaseIndex::class)->name('purchases.index');
Route::get('/purchases/create', PurchaseForm::class)->name('purchases.create');
Route::get('/purchases/{purchase}', PurchaseShow::class)->name('purchases.show');
Route::get('/purchases/{purchase}/edit', PurchaseForm::class)->name('purchases.edit');

Route::get('/payments', PaymentIndex::class)->name('payments.index');
