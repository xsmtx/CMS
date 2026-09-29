<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Intelligence\AllocateCosts;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Intelligence\AllocationStrategy;
use App\Domain\Intelligence\CostPeriod;
use App\Domain\Intelligence\CostScope;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Intelligence\Models\CostEntry;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * What each service costs to run (§21).
 *
 * **Core ships no allocation**, which is the decision tax, dunning and
 * placement all made: how €200 lands on forty accounts is somebody's
 * commercial judgement. What this file pins is that whichever judgement was
 * chosen, the arithmetic is right and the figure says which judgement made
 * it — a margin an operator cannot check is a margin they will believe when
 * it is wrong.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    /*
     * No `organization_id` here on purpose. `CustomerFactory` creates the
     * customer its own child organization, which is what a real customer
     * has — forcing it into the provider's own organization is what hid a
     * scoping bug that made these sweeps find nothing on a real
     * installation.
     */
    $this->customer = Customer::factory()->create(['company_name' => 'Acme Ltd']);

    $this->month = CarbonImmutable::parse('2026-10-01');
    $this->allocator = app(AllocateCosts::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function aCostedServer(Organization $provider, string $name = 'web-7'): Server
{
    return Server::factory()->create([
        'organization_id' => $provider->id,
        'name' => $name,
    ]);
}

function aHostedService(Customer $customer, ?Server $server, string $name): Service
{
    return Service::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'server_id' => $server?->id,
        'name' => $name,
        'status' => ServiceStatus::Active,
        'currency_code' => 'EUR',
        'recurring_minor' => 1990,
    ]);
}

it('shares a server evenly and says so', function (): void {
    $server = aCostedServer($this->provider);
    $a = aHostedService($this->customer, $server, 'one');
    $b = aHostedService($this->customer, $server, 'two');

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(20000)
        ->create(['organization_id' => $this->provider->id, 'label' => 'AX102']);

    $shares = $this->allocator->forMonth($this->provider->id, $this->month);

    expect($shares[$a->id][0]->amount->minorUnits)->toBe(10000)
        ->and($shares[$b->id][0]->amount->minorUnits)->toBe(10000)
        // The arithmetic that produced it, on the figure itself.
        ->and($shares[$a->id][0]->strategy)->toBe(AllocationStrategy::Even)
        ->and($shares[$a->id][0]->across)->toBe(2);
});

/** `Money::allocate()` loses no cent, which is the whole reason it exists. */
it('loses no minor unit splitting three ways', function (): void {
    $server = aCostedServer($this->provider);
    $ids = [];

    foreach (['one', 'two', 'three'] as $name) {
        $ids[] = aHostedService($this->customer, $server, $name)->id;
    }

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(10000)
        ->create(['organization_id' => $this->provider->id]);

    $shares = $this->allocator->forMonth($this->provider->id, $this->month);

    $total = array_sum(array_map(
        static fn (string $id) => $shares[$id][0]->amount->minorUnits,
        $ids,
    ));

    expect($total)->toBe(10000);
});

it('divides a yearly cost down to a month', function (): void {
    $server = aCostedServer($this->provider);
    $service = aHostedService($this->customer, $server, 'one');

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(120000)
        ->every(CostPeriod::Yearly)
        ->create(['organization_id' => $this->provider->id]);

    $shares = $this->allocator->forMonth($this->provider->id, $this->month);

    expect($shares[$service->id][0]->amount->minorUnits)->toBe(10000);
});

/**
 * A migration paid for once in March is not a twelfth of anything: spreading
 * it makes eleven months look worse than they were and March look better.
 */
it('charges a one-off in its own month and no other', function (): void {
    $server = aCostedServer($this->provider);
    $service = aHostedService($this->customer, $server, 'one');

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(50000)
        ->every(CostPeriod::OneOff)
        ->create([
            'organization_id' => $this->provider->id,
            'starts_on' => '2026-10-14',
        ]);

    expect($this->allocator->forMonth($this->provider->id, $this->month))
        ->toHaveKey($service->id);

    expect($this->allocator->forMonth($this->provider->id, $this->month->addMonth()))
        ->toBe([]);
});

/** A server bought in June did not cost anything in May. */
it('leaves a cost out of the months before it started', function (): void {
    $server = aCostedServer($this->provider);
    aHostedService($this->customer, $server, 'one');

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(20000)
        ->create([
            'organization_id' => $this->provider->id,
            'starts_on' => '2026-10-01',
        ]);

    expect($this->allocator->forMonth($this->provider->id, $this->month->subMonth()))
        ->toBe([]);
});

/**
 * A server bought last week with no accounts on it costs exactly as much as
 * a full one. A report that dropped it would understate the month — the one
 * direction a cost report must never be wrong in.
 */
it('keeps a cost that reached no service', function (): void {
    $server = aCostedServer($this->provider);

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(20000)
        ->create(['organization_id' => $this->provider->id, 'label' => 'Empty box']);

    $unallocated = $this->allocator->unallocated($this->provider->id, $this->month);

    expect($unallocated)->toHaveCount(1)
        ->and($unallocated[0]->label)->toBe('Empty box')
        ->and($unallocated[0]->amount->minorUnits)->toBe(20000)
        ->and($unallocated[0]->across)->toBe(0);
});

it('shares the installation across everything', function (): void {
    $a = aHostedService($this->customer, null, 'one');
    $b = aHostedService($this->customer, null, 'two');

    CostEntry::factory()
        ->costing(10000)
        ->create(['organization_id' => $this->provider->id, 'label' => 'The office']);

    $shares = $this->allocator->forMonth($this->provider->id, $this->month);

    expect($shares[$a->id][0]->amount->minorUnits)->toBe(5000)
        ->and($shares[$b->id][0]->amount->minorUnits)->toBe(5000);
});

it('weights by a reading the graph holds', function (): void {
    $server = aCostedServer($this->provider);
    $heavy = aHostedService($this->customer, $server, 'heavy');
    $light = aHostedService($this->customer, $server, 'light');

    costReading($heavy->id, 0.75);
    costReading($light->id, 0.25);

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(10000)
        ->weightedBy(MetricKind::DiskUsed)
        ->create(['organization_id' => $this->provider->id]);

    $shares = $this->allocator->forMonth($this->provider->id, $this->month);

    expect($shares[$heavy->id][0]->amount->minorUnits)->toBe(7500)
        ->and($shares[$light->id][0]->amount->minorUnits)->toBe(2500)
        ->and($shares[$heavy->id][0]->strategy)->toBe(AllocationStrategy::Weighted)
        ->and($shares[$heavy->id][0]->metric)->toBe(MetricKind::DiskUsed->value);
});

/**
 * Zero for a service nothing reported is the answer that makes a loss
 * disappear — the unmonitored box would carry no cost and look like the most
 * profitable thing on the estate. `ScorePlacement` settled this once; the
 * same reasoning applies to money.
 */
it('assumes an unmeasured service is average, never free', function (): void {
    $server = aCostedServer($this->provider);
    $measured = aHostedService($this->customer, $server, 'measured');
    $unmeasured = aHostedService($this->customer, $server, 'unmeasured');

    costReading($measured->id, 0.5);

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(10000)
        ->weightedBy(MetricKind::DiskUsed)
        ->create(['organization_id' => $this->provider->id]);

    $shares = $this->allocator->forMonth($this->provider->id, $this->month);

    expect($shares[$unmeasured->id][0]->amount->minorUnits)->toBe(5000)
        // And the row says the weight was assumed rather than measured.
        ->and($shares[$unmeasured->id][0]->assumed)->toBeTrue()
        ->and($shares[$measured->id][0]->assumed)->toBeFalse();
});

/**
 * A row claiming a weighting that did not happen is the lie the whole class
 * is arranged against.
 */
it('falls back to even and says even when nothing is measured', function (): void {
    $server = aCostedServer($this->provider);
    $a = aHostedService($this->customer, $server, 'one');
    aHostedService($this->customer, $server, 'two');

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(10000)
        ->weightedBy(MetricKind::DiskUsed)
        ->create(['organization_id' => $this->provider->id]);

    $shares = $this->allocator->forMonth($this->provider->id, $this->month);

    expect($shares[$a->id][0]->amount->minorUnits)->toBe(5000)
        ->and($shares[$a->id][0]->strategy)->toBe(AllocationStrategy::Even);
});

/** A suspended account still occupies the disk it is suspended on. */
it('counts a suspended service as occupying the machine', function (): void {
    $server = aCostedServer($this->provider);
    $active = aHostedService($this->customer, $server, 'active');
    $suspended = aHostedService($this->customer, $server, 'suspended');
    $suspended->forceFill(['status' => ServiceStatus::Suspended])->save();

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(10000)
        ->create(['organization_id' => $this->provider->id]);

    $shares = $this->allocator->forMonth($this->provider->id, $this->month);

    expect($shares[$active->id][0]->amount->minorUnits)->toBe(5000)
        ->and($shares)->toHaveKey($suspended->id);
});

it('leaves a terminated service out of it', function (): void {
    $server = aCostedServer($this->provider);
    $active = aHostedService($this->customer, $server, 'active');
    $gone = aHostedService($this->customer, $server, 'gone');
    $gone->forceFill(['status' => ServiceStatus::Terminated])->save();

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(10000)
        ->create(['organization_id' => $this->provider->id]);

    $shares = $this->allocator->forMonth($this->provider->id, $this->month);

    expect($shares[$active->id][0]->amount->minorUnits)->toBe(10000)
        ->and($shares)->not->toHaveKey($gone->id);
});

it('records a cost from the screen', function (): void {
    $server = aCostedServer($this->provider);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/intelligence/costs', [
            'label' => 'Hetzner AX102',
            'vendor' => 'Hetzner',
            'scope' => CostScope::Server->value,
            'subject_id' => $server->id,
            'currency_code' => 'eur',
            'amount_minor' => 20000,
            'period' => CostPeriod::Monthly->value,
            'strategy' => AllocationStrategy::Even->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $entry = CostEntry::query()->sole();

    expect($entry->label)->toBe('Hetzner AX102')
        // Upper-cased at the write, like every other writer of a currency
        // column.
        ->and($entry->currency_code)->toBe('EUR')
        ->and($entry->subject_id)->toBe($server->id);
});

/**
 * A metric stored against an even split is a setting read by nothing, which
 * is the trap the tax screen shipped three of.
 */
it('does not store a metric on an even split', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/intelligence/costs', [
            'label' => 'The office',
            'scope' => CostScope::Installation->value,
            'currency_code' => 'EUR',
            'amount_minor' => 10000,
            'period' => CostPeriod::Monthly->value,
            'strategy' => AllocationStrategy::Even->value,
            'metric' => MetricKind::DiskUsed->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(CostEntry::query()->sole()->metric)->toBeNull();
});

/**
 * A cost that is income is a credit note, and letting one in here would be a
 * second way to move money.
 */
it('refuses a negative cost', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/intelligence/costs', [
            'label' => 'A refund',
            'scope' => CostScope::Installation->value,
            'currency_code' => 'EUR',
            'amount_minor' => -500,
            'period' => CostPeriod::Monthly->value,
            'strategy' => AllocationStrategy::Even->value,
        ])
        ->assertSessionHasErrors('amount_minor');
});

it('drives the screen and sends each enum its own shape', function (): void {
    CostEntry::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/costs')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];

            expect($props['entries']['data'])->toHaveCount(1);

            $scopes = collect($props['options']['scopes'])->keyBy('value');

            foreach (CostScope::cases() as $scope) {
                expect($scopes[$scope->value]['needsSubject'])->toBe($scope->needsSubject());
            }

            $strategies = collect($props['options']['strategies'])->keyBy('value');

            foreach (AllocationStrategy::cases() as $strategy) {
                expect($strategies[$strategy->value]['needsMetric'])->toBe($strategy->needsMetric());
            }
        });
});

it('refuses the screen to somebody without the permission', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/intelligence/costs')
        ->assertForbidden();
});

it('removes one from the screen', function (): void {
    $entry = CostEntry::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->delete("/admin/intelligence/costs/{$entry->id}")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(CostEntry::query()->count())->toBe(0);
});

/**
 * A current reading for a service, the way the graph holds one.
 *
 * A service's node key is its own id (`ProjectCoreResources`), which is what
 * makes the allocator's lookup one query rather than one per service.
 */
function costReading(string $serviceId, float $value): void
{
    $provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    $node = app(ResourceGraph::class)->upsertNode(
        organizationId: $provider->id,
        kind: 'service',
        nodeKey: $serviceId,
        label: $serviceId,
        source: 'test-costs',
    );

    ResourceMetric::query()->create([
        'organization_id' => $provider->id,
        'resource_node_id' => $node->id,
        'metric' => MetricKind::DiskUsed->value,
        'unit' => MetricKind::DiskUsed->unit()->value,
        'value' => $value,
        'sampled_at' => CarbonImmutable::now(),
        'stale_after_seconds' => 3600,
        'source' => 'test-costs',
    ]);
}
