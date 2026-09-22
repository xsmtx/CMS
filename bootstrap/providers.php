<?php

declare(strict_types=1);

use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\OrderingServiceProvider;
use App\Providers\OrganizationServiceProvider;
use App\Providers\PlatformServiceProvider;
use App\Providers\ProvisioningServiceProvider;

return [
    AppServiceProvider::class,
    OrganizationServiceProvider::class,
    OrderingServiceProvider::class,
    BillingServiceProvider::class,
    ProvisioningServiceProvider::class,
    PlatformServiceProvider::class,
    AccessServiceProvider::class,
    HorizonServiceProvider::class,
];
