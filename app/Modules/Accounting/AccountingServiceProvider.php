<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\Services\AccountingPoster;
use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Inventory\Events\InventoryAdjusted;
use App\Modules\Inventory\Events\TransferReceived;
use App\Modules\Payments\Events\PaymentRecorded;
use App\Modules\Purchasing\Events\PurchaseReceived;
use App\Modules\Sales\Events\SaleCancelled;
use App\Modules\Sales\Events\SaleCompleted;
use App\Modules\Sales\Events\SaleReturnCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires business events to automatic journal postings. Listeners run synchronously inside the
 * business transaction, so a document and its journal entry are committed (or rolled back) together.
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AccountResolver::class);
    }

    public function boot(): void
    {
        Event::listen(SaleCompleted::class, fn (SaleCompleted $e) => app(AccountingPoster::class)->sale($e->sale));
        Event::listen(SaleCancelled::class, fn (SaleCancelled $e) => app(AccountingPoster::class)->saleCancelled($e->sale));
        Event::listen(SaleReturnCompleted::class, fn (SaleReturnCompleted $e) => app(AccountingPoster::class)->saleReturn($e->saleReturn));
        Event::listen(PaymentRecorded::class, fn (PaymentRecorded $e) => app(AccountingPoster::class)->payment($e->payment));
        Event::listen(PurchaseReceived::class, fn (PurchaseReceived $e) => app(AccountingPoster::class)->purchaseReceived($e->purchaseOrder));
        Event::listen(InventoryAdjusted::class, fn (InventoryAdjusted $e) => app(AccountingPoster::class)->inventoryAdjusted($e->transaction));
        Event::listen(TransferReceived::class, fn (TransferReceived $e) => app(AccountingPoster::class)->transferReceived($e->transfer));
    }
}
