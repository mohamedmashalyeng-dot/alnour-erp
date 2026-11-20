<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Models\TaxRate;
use App\Modules\Core\Services\Settings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\SalaryChange;
use App\Modules\Imports\Models\ImportLog;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Offline\Models\PosDevice;
use App\Modules\Offline\Models\SyncConflict;
use App\Modules\Optical\Models\LoyaltyTransaction;
use App\Modules\Optical\Models\Prescription;
use App\Modules\Optical\Models\RepairOrder;
use App\Modules\Optical\Models\Warranty;
use App\Modules\Optical\Models\WarrantyClaim;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\EmployeeAdvance;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnItem;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\BankReconciliation;
use App\Modules\Treasury\Models\CashRegister;
use App\Modules\Treasury\Models\CashShift;
use App\Modules\Treasury\Models\TreasuryMovement;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BranchAccess::class);
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        // Super admin bypasses permission checks (branch isolation is still applied via BranchAccess::allowedIds).
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // Catch N+1 queries and silently dropped attributes during development and tests.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Stable, short morph names (DB never stores PHP class names).
        Relation::enforceMorphMap([
            'user' => User::class,
            'branch' => Branch::class,
            'category' => Category::class,
            'brand' => Brand::class,
            'product' => Product::class,
            'product_image' => ProductImage::class,
            'supplier' => Supplier::class,
            'tax_rate' => TaxRate::class,
            'inventory' => Inventory::class,
            'stock_transfer' => StockTransfer::class,
            'stock_transfer_item' => StockTransferItem::class,
            'customer' => Customer::class,
            'purchase_order' => PurchaseOrder::class,
            'purchase_order_item' => PurchaseOrderItem::class,
            'sale' => Sale::class,
            'sale_item' => SaleItem::class,
            'sale_return' => SaleReturn::class,
            'sale_return_item' => SaleReturnItem::class,
            'payment' => Payment::class,
            // Phase 3
            'inventory_transaction' => InventoryTransaction::class,
            'account' => Account::class,
            'accounting_period' => AccountingPeriod::class,
            'journal_entry' => JournalEntry::class,
            'cash_register' => CashRegister::class,
            'cash_shift' => CashShift::class,
            'bank_account' => BankAccount::class,
            'bank_reconciliation' => BankReconciliation::class,
            'treasury_movement' => TreasuryMovement::class,
            'expense' => Expense::class,
            'expense_category' => ExpenseCategory::class,
            'attachment' => Attachment::class,
            'employee' => Employee::class,
            'employee_advance' => EmployeeAdvance::class,
            'payroll_run' => PayrollRun::class,
            'import_log' => ImportLog::class,
            'pos_device' => PosDevice::class,
            'sync_conflict' => SyncConflict::class,
            'attendance_record' => AttendanceRecord::class,
            'leave_request' => LeaveRequest::class,
            'salary_change' => SalaryChange::class,
            'performance_review' => PerformanceReview::class,
            'prescription' => Prescription::class,
            'warranty' => Warranty::class,
            'warranty_claim' => WarrantyClaim::class,
            'repair_order' => RepairOrder::class,
            'loyalty_transaction' => LoyaltyTransaction::class,
            'website_inquiry' => \App\Modules\Website\Models\WebsiteInquiry::class,
        ]);

        Password::defaults(fn () => Password::min(10)->letters()->mixedCase()->numbers());

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip());
        });
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Permission helper usable in Blade as @canany / @can; also expose a sidebar gate.
        Gate::define('access-any-branch', fn (User $user) => app(BranchAccess::class)->allowedIds($user) !== []);
    }
}
