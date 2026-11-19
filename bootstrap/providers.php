<?php

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Optical\OpticalServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AccountingServiceProvider::class,
    OpticalServiceProvider::class,
];
