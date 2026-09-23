<?php

declare(strict_types=1);

use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AutomationServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\NotificationServiceProvider;
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
    AutomationServiceProvider::class,
    DomainServiceProvider::class,
    NotificationServiceProvider::class,
    PlatformServiceProvider::class,
    AccessServiceProvider::class,
    HorizonServiceProvider::class,
];
