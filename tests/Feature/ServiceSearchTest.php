<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\ProductType;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\CustomFieldEntity;
use App\Domain\Crm\CustomFieldType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Every criterion the products and services screen offers is a criterion
 * that runs. A filter that silently matches nothing because the column does
 * not exist is worse than no filter: an operator concludes the service is
 * not there.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->operator = StaffUser::factory()->create();
    $this->operator->assignRole(SystemRole::Administrator);
    $this->operator = $this->operator->fresh();
});

it('hides the services of a closed account until somebody asks', function (): void {
    $trading = Customer::factory()->create(['company_name' => 'Still Trading']);
    $closed = Customer::factory()->create([
        'company_name' => 'Gone Last Year',
        'status' => CustomerStatus::Closed->value,
    ]);

    Service::factory()->forCustomer($trading)->create();
    Service::factory()->forCustomer($closed)->create();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 1)
            ->where('services.data.0.customer', 'Still Trading'));

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services?inactive=1')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 2));
});

it('filters by the product type, and counts the types that exist', function (): void {
    $shared = Product::factory()->ofType(ProductType::SharedHosting)->create();
    $vps = Product::factory()->ofType(ProductType::Vps)->create();

    Service::factory()->count(2)->create(['product_id' => $shared->id]);
    Service::factory()->create(['product_id' => $vps->id]);

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services?product_type='.ProductType::Vps->value)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 1)
            // The rail across the top is built from the rows, so it never
            // offers a type nobody sells.
            ->has('types', 2));
});

it('filters by the server a service runs on', function (): void {
    $group = ServerGroup::factory()->create();
    $one = Server::factory()->inGroup($group)->create(['name' => 'web-01']);
    $two = Server::factory()->inGroup($group)->create(['name' => 'web-02']);

    Service::factory()->on($one)->create();
    Service::factory()->on($two)->create();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services?server='.$two->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 1)
            ->where('services.data.0.server', 'web-02'));
});

it('filters by the billing cycle and by the status', function (): void {
    Service::factory()->create([
        'billing_cycle' => BillingCycle::Annually->value,
        'status' => ServiceStatus::Active->value,
    ]);

    Service::factory()->create([
        'billing_cycle' => BillingCycle::Monthly->value,
        'status' => ServiceStatus::Suspended->value,
    ]);

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services?billing_cycle='.BillingCycle::Annually->value)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 1));

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services?status='.ServiceStatus::Suspended->value)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 1));
});

it('finds a service by its domain, wildcards and all', function (): void {
    Service::factory()->create(['domain' => 'kayabilisim.com.tr']);
    Service::factory()->create(['domain' => 'example.org']);

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services?domain=kaya%25')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 1)
            ->where('services.data.0.domain', 'kayabilisim.com.tr'));
});

it('finds a service by the whole name of the person behind it', function (): void {
    $customer = Customer::factory()->create(['company_name' => null, 'legal_name' => null]);
    Contact::factory()->forCustomer($customer)->primary()->create([
        'first_name' => 'Zeynep',
        'last_name' => 'Kaya',
    ]);

    Service::factory()->forCustomer($customer)->create();
    Service::factory()->create();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services?client=Zeynep+Kaya')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 1));
});

it('filters by the card on the client file', function (): void {
    $paying = Customer::factory()->create();
    PaymentMethod::factory()->create([
        'customer_id' => $paying->id,
        'organization_id' => $paying->organization_id,
        'gateway' => 'iyzico',
        'is_default' => true,
    ]);

    Service::factory()->forCustomer($paying)->create();
    Service::factory()->create();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services?gateway=iyzico')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 1)
            // The detail the `+` opens, sent with the list rather than
            // fetched per row.
            ->where('services.data.0.detail.paymentMethod', 'iyzico visa •••• 4242'));
});

/**
 * A service has no custom fields; its customer does. "Find every service
 * belonging to a customer in this Vergi Dairesi" is the real question, and
 * this is the honest way to answer it.
 */
it('filters by a custom field belonging to the customer', function (): void {
    $definition = CustomFieldDefinition::factory()->create([
        'organization_id' => $this->operator->organization_id,
        'entity_type' => CustomFieldEntity::Customer->value,
        'key' => 'vergi_dairesi',
        'label' => 'Vergi Dairesi',
        'type' => CustomFieldType::Text->value,
    ]);

    $customer = Customer::factory()->create();

    $customer->customFieldValues()->create([
        'organization_id' => $customer->organization_id,
        'definition_id' => $definition->id,
        'value' => 'Kadıköy',
    ]);

    Service::factory()->forCustomer($customer)->create();
    Service::factory()->create();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services?custom_field=vergi_dairesi&custom_value=Kadıköy')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 1));
});

it('ignores a custom field nobody defined rather than matching nothing', function (): void {
    Service::factory()->count(2)->create();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services?custom_field=invented&custom_value=whatever')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('services.data', 2));
});

it('carries the order number and promotion code into the row detail', function (): void {
    $customer = Customer::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'promotion_code' => 'YILBASI25',
    ]);

    Service::factory()->forCustomer($customer)->create([
        'order_id' => $order->id,
        'username' => 'kayabil',
    ]);

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('services.data.0.detail.orderNumber', $order->number)
            ->where('services.data.0.detail.promotionCode', 'YILBASI25')
            ->where('services.data.0.detail.username', 'kayabil'));
});

/**
 * Strict mode only reports a lazy load when the query returned more than
 * one row, so the fixture has two of everything on purpose.
 */
it('opens the list without lazily loading anything the row reads', function (): void {
    $group = ServerGroup::factory()->create();
    $server = Server::factory()->inGroup($group)->create();
    $product = Product::factory()->create();

    for ($i = 0; $i < 2; $i++) {
        $customer = Customer::factory()->create(['company_name' => null, 'legal_name' => null]);
        Contact::factory()->forCustomer($customer)->primary()->create();

        PaymentMethod::factory()->create([
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
            'is_default' => true,
        ]);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);

        Service::factory()->forCustomer($customer)->on($server)->create([
            'product_id' => $product->id,
            'order_id' => $order->id,
        ]);
    }

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services')
        ->assertOk();
});
