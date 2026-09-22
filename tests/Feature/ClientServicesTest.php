<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceEvent;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create();

    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();

    $this->group = ServerGroup::factory()->create();
    $this->server = Server::factory()->inGroup($this->group)->create(['name' => 'node-07']);

    $this->service = Service::factory()
        ->forCustomer($this->customer)
        ->on($this->server)
        ->active()
        ->create(['name' => 'Starter Plan', 'username' => 'bobhost', 'password' => 'provider-issued']);
});

it('lists the services this customer is running', function (): void {
    $this->actingAs($this->owner, 'client')
        ->get('/client/services')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Services/Index')
            ->has('services', 1)
            ->where('services.0.name', 'Starter Plan'));
});

it('does not list a terminated service', function (): void {
    Service::factory()->forCustomer($this->customer)->status(ServiceStatus::Terminated)->create();

    $this->actingAs($this->owner, 'client')
        ->get('/client/services')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('services', 1));
});

it('gives the customer the credentials that are theirs', function (): void {
    // The one credential in this platform meant to be read by the person it
    // belongs to: they cannot sign in without it.
    $this->actingAs($this->owner, 'client')
        ->get("/client/services/{$this->service->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Services/Show')
            ->where('service.credentials.username', 'bobhost')
            ->where('service.credentials.password', 'provider-issued'));
});

it('never tells the customer which node, which module or which account id', function (): void {
    ServiceEvent::factory()->forService($this->service)->create([
        'message' => 'createacct failed on node-07: quota exceeded',
    ]);

    // An operator's vocabulary. A customer reading "placement failed on
    // node-07" learns only that something they do not control is broken.
    $this->actingAs($this->owner, 'client')
        ->get("/client/services/{$this->service->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->missing('service.server')
            ->missing('service.module')
            ->missing('service.externalId')
            ->missing('service.events'))
        ->assertDontSee('node-07');
});

it('withholds credentials for a service that is not usable', function (): void {
    $this->service->forceFill(['status' => ServiceStatus::Failed->value])->save();

    $this->actingAs($this->owner, 'client')
        ->get("/client/services/{$this->service->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('service.credentials', null))
        ->assertDontSee('provider-issued');
});

it('hides another customers service', function (): void {
    $stranger = Service::factory()->forCustomer(Customer::factory()->create())->active()->create();

    $this->actingAs($this->owner, 'client')
        ->get("/client/services/{$stranger->id}")
        ->assertNotFound();
});

it('refuses services to a contact without the permission', function (): void {
    $member = Contact::factory()->forCustomer($this->customer)->create();
    $member->assignRole(SystemRole::PortalMember);

    $this->actingAs($member->fresh(), 'client')
        ->get('/client/services')
        ->assertForbidden();
});
