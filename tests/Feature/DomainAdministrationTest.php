<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Domains\TldCatalog;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Domains\DomainAction;
use App\Domain\Domains\DomainOperation;
use App\Domain\Domains\DomainStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Jobs\RegisterDomain;
use App\Infrastructure\Domains\Jobs\RunDomainAction;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\Models\Tld;
use App\Infrastructure\Domains\Models\TldPrice;
use App\Infrastructure\Domains\RegistrarRegistry;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeRegistrar;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $registry = new RegistrarRegistry;
    $registry->register(new FakeRegistrar);
    $this->app->instance(RegistrarRegistry::class, $registry);

    $this->operator = StaffUser::factory()->create();
    $this->operator->assignRole(SystemRole::Administrator);
    $this->operator = $this->operator->fresh();

    $this->tld = Tld::factory()->extension('com')->create(['registrar' => 'fake']);
    $this->customer = Customer::factory()->create();

    $this->domain = Domain::factory()
        ->forCustomer($this->customer)
        ->forTld($this->tld)
        ->named('example', 'com')
        ->active(20)
        ->create();

    app(TldCatalog::class)->forget();
});

it('lists domains with the counts an operator opens the screen for', function (): void {
    Domain::factory()->forCustomer($this->customer)->status(DomainStatus::Failed)->create();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/domains')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Domains/Index')
            ->has('domains.data', 2)
            ->where('counts.failed', 1)
            // 20 days out, which is inside the 45-day window.
            ->where('counts.expiring', 1));
});

it('shows a domain with only the actions its registrar supports', function (): void {
    $this->actingAs($this->operator, 'staff')
        ->get("/admin/domains/{$this->domain->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Domains/Show')
            ->where('domain.name', 'example.com')
            ->where('can.nameservers', true)
            ->where('can.sync', true));
});

it('offers nothing to run when the registrar is not configured here', function (): void {
    $orphan = Domain::factory()->forCustomer($this->customer)->create(['registrar' => 'gone']);

    $this->actingAs($this->operator, 'staff')
        ->get("/admin/domains/{$orphan->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.nameservers', false)
            ->where('can.renew', false));
});

it('queues a registration rather than running it in the request', function (): void {
    Queue::fake();

    $pending = Domain::factory()->forCustomer($this->customer)->forTld($this->tld)->create();

    $this->actingAs($this->operator, 'staff')
        ->post("/admin/domains/{$pending->id}/register")
        ->assertRedirect();

    Queue::assertPushed(RegisterDomain::class);
});

it('queues a nameserver change with what the operator typed', function (): void {
    Queue::fake();

    $this->actingAs($this->operator, 'staff')
        ->post("/admin/domains/{$this->domain->id}/actions", [
            'operation' => 'set_nameservers',
            'nameservers' => ['ns1.example.test', 'ns2.example.test'],
        ])
        ->assertRedirect();

    Queue::assertPushed(
        RunDomainAction::class,
        fn (RunDomainAction $job): bool => $job->operation === DomainOperation::SetNameservers
            && $job->nameservers === ['ns1.example.test', 'ns2.example.test'],
    );
});

it('refuses a registration through the actions route', function (): void {
    // It spends money and has its own permission.
    $this->actingAs($this->operator, 'staff')
        ->post("/admin/domains/{$this->domain->id}/actions", ['operation' => 'register'])
        ->assertSessionHasErrors('operation');
});

it('refuses a nameserver that is not a hostname', function (): void {
    $this->actingAs($this->operator, 'staff')
        ->post("/admin/domains/{$this->domain->id}/actions", [
            'operation' => 'set_nameservers',
            'nameservers' => ['not a hostname'],
        ])
        ->assertSessionHasErrors('nameservers.0');
});

it('refuses the domain screen to staff without the permission', function (): void {
    $support = StaffUser::factory()->create();
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->get('/admin/domains')
        ->assertForbidden();
});

it('saves a TLD and its whole price matrix', function (): void {
    $this->actingAs($this->operator, 'staff')
        ->post('/admin/catalog/tlds', [
            'extension' => 'dev',
            'registrar' => 'fake',
            'min_years' => 1,
            'max_years' => 2,
            'status' => 'active',
            'grace_days' => 30,
            'redemption_days' => 30,
            'prices' => [
                ['action' => 'register', 'years' => 1, 'currency_code' => 'eur', 'amount_minor' => 1500],
                ['action' => 'renew', 'years' => 1, 'currency_code' => 'eur', 'amount_minor' => 1700],
            ],
        ])
        ->assertRedirect();

    $tld = Tld::query()->withoutGlobalScope('organization')->where('extension', 'dev')->sole();

    expect($tld->prices()->count())->toBe(2)
        ->and($tld->prices()->where('action', 'register')->sole()->currency_code)->toBe('EUR');
});

it('replaces the matrix whole, so a removed cell is a term no longer sold', function (): void {
    TldPrice::factory()->forTld($this->tld)->create([
        'action' => DomainAction::Register->value,
        'years' => 1,
        'currency_code' => 'EUR',
        'amount_minor' => 1200,
    ]);
    TldPrice::factory()->forTld($this->tld)->create([
        'action' => DomainAction::Register->value,
        'years' => 2,
        'currency_code' => 'EUR',
        'amount_minor' => 2200,
    ]);

    $this->actingAs($this->operator, 'staff')
        ->put("/admin/catalog/tlds/{$this->tld->id}", [
            'extension' => 'com',
            'registrar' => 'fake',
            'min_years' => 1,
            'max_years' => 10,
            'status' => 'active',
            'grace_days' => 30,
            'redemption_days' => 30,
            'prices' => [
                ['action' => 'register', 'years' => 1, 'currency_code' => 'EUR', 'amount_minor' => 1300],
            ],
        ])
        ->assertRedirect();

    // An operator who removes a cell expects it to be gone.
    expect($this->tld->prices()->count())->toBe(1)
        ->and($this->tld->prices()->sole()->amount->minorUnits)->toBe(1300);
});

it('refuses to delete an extension that still has domains', function (): void {
    $this->actingAs($this->operator, 'staff')
        ->delete("/admin/catalog/tlds/{$this->tld->id}")
        ->assertRedirect();

    expect(Tld::query()->withoutGlobalScope('organization')->count())->toBe(1);
});

it('lets a customer see and manage their own domain and nobody elses', function (): void {
    $owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $owner->assignRole(SystemRole::AccountOwner);
    $owner = $owner->fresh();

    $this->actingAs($owner, 'client')
        ->get("/client/domains/{$this->domain->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Domains/Show')
            ->where('domain.name', 'example.com')
            // An operator's vocabulary, absent from the customer's screen.
            ->missing('domain.registrar')
            ->missing('domain.externalId')
            ->missing('domain.events'));

    $stranger = Domain::factory()->forCustomer(Customer::factory()->create())->active()->create();

    $this->actingAs($owner, 'client')
        ->get("/client/domains/{$stranger->id}")
        ->assertNotFound();
});

it('queues a customers nameserver change and refuses a single one', function (): void {
    Queue::fake();

    $owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $owner->assignRole(SystemRole::AccountOwner);
    $owner = $owner->fresh();

    // A registry rejects one nameserver and will not say why in a way a
    // customer could act on.
    $this->actingAs($owner, 'client')
        ->put("/client/domains/{$this->domain->id}/nameservers", ['nameservers' => ['ns1.only.test']])
        ->assertSessionHasErrors('nameservers');

    $this->actingAs($owner, 'client')
        ->put("/client/domains/{$this->domain->id}/nameservers", [
            'nameservers' => ['ns1.new.test', 'ns2.new.test'],
        ])
        ->assertRedirect();

    Queue::assertPushed(RunDomainAction::class);
});

it('hands a customer their transfer code once, without storing it', function (): void {
    $owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $owner->assignRole(SystemRole::AccountOwner);
    $owner = $owner->fresh();

    $this->actingAs($owner, 'client')
        ->post("/client/domains/{$this->domain->id}/transfer-code")
        ->assertRedirect()
        ->assertSessionHas('transferCode', 'EPP-SECRET-CODE');

    expect(json_encode($this->domain->fresh()?->getAttributes()))
        ->not->toContain('EPP-SECRET-CODE');
});
