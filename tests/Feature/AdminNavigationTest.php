<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Service;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Every destination the menu offers, opened.
 *
 * The menu is the one piece of this panel nobody can be trained out of, and
 * a menu that advertises a screen which answers 404 is worse than one that
 * says nothing. The front-end test covers the shape of the map; this one
 * covers whether the map points at anything.
 *
 * The list is written out by hand rather than parsed out of the Vue file.
 * Parsing would pass the day somebody renamed a route and the map with it,
 * which is exactly the day this should fail.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    // The owner, because Apps and Integrations is shut to everybody else.
    $this->owner = StaffUser::factory()->create();
    $this->owner->assignRole(SystemRole::SuperAdmin);
    $this->owner = $this->owner->fresh();
});

it('answers every destination in the admin menu', function (string $path): void {
    $this->actingAs($this->owner, 'staff')
        ->get($path)
        ->assertOk();
})->with([
    '/admin',

    // Clients
    '/admin/customers',
    '/admin/customer-users',
    '/admin/clients/create',
    '/admin/services',
    '/admin/services?product_type=shared_hosting',
    '/admin/cancellations',
    '/admin/services/addons',
    '/admin/domains',
    '/admin/domains?status=active&domain=kaya%25',
    '/admin/organizations',

    // Orders
    '/admin/orders',
    '/admin/orders?status=pending',
    '/admin/orders?status=fraud_review',
    '/admin/orders?client=zey&ip=10.0',
    '/admin/orders/review',

    // Billing
    '/admin/transactions',
    '/admin/transactions?direction=in',
    '/admin/transactions?direction=out',
    '/admin/transactions/add',
    '/admin/transactions?kind=payment',
    '/admin/invoices',
    '/admin/invoices?status=overdue',
    '/admin/billing/gateway-log',
    '/admin/automation/dunning',
    '/admin/catalog/currencies',

    // Domains
    '/admin/catalog/tlds',

    // Support
    '/admin/support',
    '/admin/support?status=customer_reply',
    '/admin/support/overview',
    '/admin/support/overview?period=last_month',
    '/admin/support?client=kaya&priority=high',
    '/admin/support/create',
    '/admin/support/replies',
    '/admin/content/announcements',
    '/admin/content/articles',

    // Utilities
    '/admin/operations',
    '/admin/automation',
    '/admin/health',
    '/admin/todo',
    '/admin/notifications/log',
    '/admin/api/activity',

    // Setup
    '/admin/catalog/products',
    '/admin/catalog/groups',
    '/admin/promotions',
    '/admin/staff',
    '/admin/roles',
    '/admin/settings',
    '/admin/notifications/templates',

    // Behind the wrench
    '/admin/apps',
    '/admin/apps/modules',
    '/admin/apps/infrastructure',
    '/admin/apps/connect',

    // The box at the top
    '/admin/search',
]);

/**
 * The search box is the one destination that takes a term, so it gets its
 * own test rather than a row in the list above.
 */
it('searches everything from one box', function (): void {
    $customer = Customer::factory()
        ->create(['company_name' => 'Meridian Freight']);

    Service::factory()
        ->forCustomer($customer)
        ->create(['domain' => 'meridian.example']);

    $this->actingAs($this->owner, 'staff')
        ->get('/admin/search?q=meridian')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Search/Index')
            // One term, two kinds of record, without the operator choosing
            // a screen first.
            ->has('groups', 2));
});
