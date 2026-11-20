<?php

use App\Http\Controllers\SessionController;
use App\Http\Controllers\UiPreferenceController;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Modules\Branches\Livewire\BranchIndex;
use App\Modules\Branches\Livewire\BranchShow;
use App\Modules\Catalog\Http\LabelController;
use App\Modules\Catalog\Livewire\BrandIndex;
use App\Modules\Catalog\Livewire\CategoryIndex;
use App\Modules\Catalog\Livewire\ProductForm;
use App\Modules\Catalog\Livewire\ProductIndex;
use App\Modules\Catalog\Livewire\ProductShow;
use App\Modules\Core\Livewire\AuditLogIndex;
use App\Modules\Core\Livewire\SettingsPage;
use App\Modules\Inventory\Livewire\MovementIndex;
use App\Modules\Inventory\Livewire\StockIndex;
use App\Modules\Inventory\Livewire\StockOperations;
use App\Modules\Inventory\Livewire\TransferCreate;
use App\Modules\Inventory\Livewire\TransferIndex;
use App\Modules\Inventory\Livewire\TransferShow;
use App\Modules\Suppliers\Livewire\SupplierIndex;
use App\Modules\Users\Livewire\UserIndex;
use Illuminate\Support\Facades\Route;

Route::get('/locale/{locale}', [SessionController::class, 'locale'])->name('locale');

// Public website (registered first so a WEBSITE_DOMAIN root wins over the dashboard's "/").
require __DIR__.'/website.php';

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [SessionController::class, 'logout'])->name('logout');
    Route::post('/branch/switch', [SessionController::class, 'switchBranch'])->name('branch.switch');
    Route::post('/preferences/pages', [UiPreferenceController::class, 'pages'])->middleware('throttle:60,1')->name('preferences.pages');
    Route::post('/preferences/pages/show-all', [UiPreferenceController::class, 'showAll'])->middleware('throttle:60,1')->name('preferences.pages.show-all');

    Route::get('/', Dashboard::class)->name('dashboard');

    // Phase 1 — foundation, catalog, inventory
    Route::get('/branches', BranchIndex::class)->name('branches.index');
    Route::get('/branches/{branch}', BranchShow::class)->name('branches.show');
    Route::get('/users', UserIndex::class)->name('users.index');
    Route::get('/categories', CategoryIndex::class)->name('categories.index');
    Route::get('/brands', BrandIndex::class)->name('brands.index');

    Route::get('/products', ProductIndex::class)->name('products.index');
    Route::get('/products/create', ProductForm::class)->name('products.create');
    Route::get('/products/labels', LabelController::class)->name('products.labels');
    Route::get('/products/{product}', ProductShow::class)->name('products.show');
    Route::get('/products/{product}/edit', ProductForm::class)->name('products.edit');

    Route::get('/inventory', StockIndex::class)->name('inventory.index');
    Route::get('/inventory/operations', StockOperations::class)->name('inventory.operations');
    Route::get('/inventory/movements', MovementIndex::class)->name('inventory.movements');
    Route::get('/transfers', TransferIndex::class)->name('transfers.index');
    Route::get('/transfers/create', TransferCreate::class)->name('transfers.create');
    Route::get('/transfers/{transfer}', TransferShow::class)->name('transfers.show');

    Route::get('/suppliers', SupplierIndex::class)->name('suppliers.index');
    Route::get('/audit-log', AuditLogIndex::class)->name('audit.index');
    Route::get('/settings', SettingsPage::class)->name('settings.index');

    // Phase 2 — sales, customers, purchasing, payments
    require __DIR__.'/sales.php';

    // Phase 3 — accounting, cash & bank, expenses, payroll
    require __DIR__.'/accounting.php';

    // Phase 4 — reporting & analytics
    require __DIR__.'/reports.php';

    // Phase 5 — spreadsheet import / export
    require __DIR__.'/imports.php';

    // Phase 6 — offline POS & synchronization
    require __DIR__.'/offline.php';

    // HR module
    require __DIR__.'/hr.php';

    // Eyewear module
    require __DIR__.'/optical.php';

    // Public website — dashboard side
    require __DIR__.'/website-admin.php';
});
