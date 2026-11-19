<?php

namespace App\Modules\Optical;

use App\Modules\Optical\Services\LoyaltyService;
use App\Modules\Optical\Services\WarrantyService;
use App\Modules\Sales\Events\SaleCancelled;
use App\Modules\Sales\Events\SaleCompleted;
use App\Modules\Sales\Events\SaleReturnCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Eyewear module hooks. Sales events are dispatched inside the sale transaction, so warranties and
 * loyalty postings commit or roll back together with the invoice.
 */
class OpticalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(SaleCompleted::class, function (SaleCompleted $e) {
            app(WarrantyService::class)->createForSale($e->sale);
            app(LoyaltyService::class)->earn($e->sale);
        });
        Event::listen(SaleCancelled::class, function (SaleCancelled $e) {
            app(WarrantyService::class)->voidForSale($e->sale);
            app(LoyaltyService::class)->reverseSale($e->sale);
        });
        Event::listen(SaleReturnCompleted::class, function (SaleReturnCompleted $e) {
            app(WarrantyService::class)->applyReturn($e->saleReturn);
            app(LoyaltyService::class)->reverseForReturn($e->saleReturn);
        });
    }
}
