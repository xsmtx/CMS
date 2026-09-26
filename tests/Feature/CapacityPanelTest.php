<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Infrastructure\CapacityOutlooks;
use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricUnit;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Factories\ResourceMetricDayFactory;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * What is running out, on the screen that shows what is arriving.
 *
 * The list is deliberately short: only resources that are actually heading
 * somewhere, soonest first. A screen of forty rows that say "not filling" is
 * a screen nobody reads to the bottom, and a capacity panel nobody reads is
 * worse than none — it is a place an operator learns to stop looking.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->climbing = function (string $key, array $values): string {
        $node = app(ResourceGraph::class)->upsertNode(
            $this->provider->id,
            ResourceKind::Server,
            $key,
            ucfirst($key),
        );

        foreach (array_values($values) as $index => $value) {
            ResourceMetricDayFactory::new()
                ->forNode($node)
                ->on(CarbonImmutable::now()->subDays(count($values) - $index)->toDateString(), $value)
                ->create([
                    'metric' => MetricKind::CpuUtilisation->value,
                    'unit' => MetricUnit::Ratio->value,
                ]);
        }

        return $node->id;
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('lists what is filling, soonest first', function (): void {
    // Climbing a point a day from 0.50 — a long way off.
    ($this->climbing)('slow', [0.50, 0.51, 0.52, 0.53, 0.54, 0.55, 0.56, 0.57]);

    // Climbing five points a day from 0.60 — days away.
    ($this->climbing)('urgent', [0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.88, 0.90]);

    $rows = app(CapacityOutlooks::class)->soonest($this->provider->id);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['node']->node_key)->toBe('urgent')
        ->and($rows[1]['node']->node_key)->toBe('slow');
});

it('leaves out what is not going anywhere', function (): void {
    ($this->climbing)('steady', [0.40, 0.41, 0.40, 0.39, 0.40, 0.41, 0.40, 0.39]);

    expect(app(CapacityOutlooks::class)->soonest($this->provider->id))->toBe([]);
});

it('leaves out a resource with less than a week behind it', function (): void {
    ($this->climbing)('new', [0.50, 0.60, 0.70]);

    expect(app(CapacityOutlooks::class)->soonest($this->provider->id))->toBe([]);
});

it('puts the answer on the telemetry screen', function (): void {
    ($this->climbing)('urgent', [0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.88, 0.90]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/resources/telemetry')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Resources/Telemetry')
            ->has('capacity', 1)
            ->where('capacity.0.nodeKey', 'urgent')
            // Two fields, as everything that crosses to the browser does: the
            // metric for the screen to reason about and the label to print.
            ->where('capacity.0.metric', MetricKind::CpuUtilisation->value)
            ->where('capacity.0.metricLabel', __('infrastructure.metrics.cpu.utilisation'))
            // How many points the line came from, because a date from eight
            // and a date from ninety deserve different belief.
            ->where('capacity.0.days', 8));
});

it('shows nothing rather than an empty panel when nothing is filling', function (): void {
    ($this->climbing)('steady', [0.40, 0.41, 0.40, 0.39, 0.40, 0.41, 0.40, 0.39]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/resources/telemetry')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('capacity', 0));
});

/**
 * A reseller's disk is not the provider's business and the other way round.
 */
it('never shows one organization another organizations capacity', function (): void {
    ($this->climbing)('urgent', [0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.88, 0.90]);

    $reseller = Organization::factory()->reseller($this->provider)->create();

    expect(app(CapacityOutlooks::class)->soonest($reseller->id))->toBe([]);
});
