<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Dcim\FitPart;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Dcim\PartKind;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Dcim\Models\HardwarePart;
use App\Infrastructure\Dcim\Models\PartFitting;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Hardware parts (§11).
 *
 * **This is about parts, not machines.** A disk outlives the machine it was
 * first fitted to, and "where has this serial been" is the question a
 * warranty claim turns on — so nothing is ever deleted or edited, and a part
 * that has been in three machines has three rows.
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

    $this->parts = app(FitPart::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function aPart(Organization $provider): HardwarePart
{
    return HardwarePart::factory()->create(['organization_id' => $provider->id]);
}

function aServer(Organization $provider, string $name): Server
{
    return Server::factory()->create([
        'organization_id' => $provider->id,
        'name' => $name,
    ]);
}

it('fits a part and records where it is', function (): void {
    $part = aPart($this->provider);
    $server = aServer($this->provider, 'web-3');

    $this->parts->fit($part, $server, $this->admin, 'New build');

    $fitting = PartFitting::query()->sole();

    expect($fitting->server_id)->toBe($server->id)
        ->and($fitting->isOpen())->toBeTrue()
        ->and($fitting->note)->toBe('New build')
        ->and($part->fresh()->currentFitting?->server_id)->toBe($server->id);
});

/**
 * The whole reason the table exists: a disk that has been in three machines
 * has three rows, and the old ones are closed rather than deleted.
 */
it('keeps a part’s history when it moves', function (): void {
    CarbonImmutable::setTestNow('2024-03-01 10:00:00');

    $part = aPart($this->provider);
    $first = aServer($this->provider, 'web-3');
    $second = aServer($this->provider, 'web-7');

    $this->parts->fit($part, $first, $this->admin);

    CarbonImmutable::setTestNow('2026-05-01 10:00:00');
    $this->parts->fit($part, $second, $this->admin);

    $fittings = PartFitting::query()->orderBy('fitted_at')->get();

    expect($fittings)->toHaveCount(2)
        // Closed, never deleted.
        ->and($fittings[0]->removed_at?->toDateString())->toBe('2026-05-01')
        ->and($fittings[0]->removed_by)->toBe($this->admin->id)
        ->and($fittings[1]->isOpen())->toBeTrue()
        ->and($fittings[1]->server_id)->toBe($second->id)
        // One machine at a time.
        ->and(PartFitting::query()->open()->count())->toBe(1);
});

/**
 * A double-press must not read as somebody pulling the disk and putting it
 * back.
 */
it('does nothing when a part is fitted to the machine it is already in', function (): void {
    $part = aPart($this->provider);
    $server = aServer($this->provider, 'web-3');

    $this->parts->fit($part, $server, $this->admin);
    $this->parts->fit($part, $server, $this->admin);

    expect(PartFitting::query()->count())->toBe(1)
        ->and(PartFitting::query()->sole()->isOpen())->toBeTrue();
});

it('takes a part out and leaves the history behind', function (): void {
    $part = aPart($this->provider);
    $server = aServer($this->provider, 'web-3');

    $this->parts->fit($part, $server, $this->admin);
    $this->parts->remove($part, $this->admin, 'Failed SMART');

    expect(PartFitting::query()->count())->toBe(1)
        ->and(PartFitting::query()->sole()->isOpen())->toBeFalse()
        ->and($part->fresh()->currentFitting)->toBeNull();
});

/** Taking out a part that is on a shelf is somebody checking, not an error. */
it('does nothing when a part that is in nothing is taken out', function (): void {
    $part = aPart($this->provider);

    $this->parts->remove($part, $this->admin);

    expect(PartFitting::query()->count())->toBe(0);
});

/**
 * Three answers, not two. A part nobody recorded a warranty for is a gap in
 * the register, and drawing it as expired would send somebody to argue with a
 * vendor who is still obliged.
 */
it('tells an expired warranty from one nobody recorded', function (): void {
    $covered = HardwarePart::factory()->create(['organization_id' => $this->provider->id]);
    $expired = HardwarePart::factory()->outOfWarranty()->create(['organization_id' => $this->provider->id]);
    $unknown = HardwarePart::factory()->withNoWarranty()->create(['organization_id' => $this->provider->id]);

    expect($covered->isOutOfWarranty())->toBeFalse()
        ->and($expired->isOutOfWarranty())->toBeTrue()
        ->and($unknown->isOutOfWarranty())->toBeNull();
});

it('drives the list, with where each part is and whether it is covered', function (): void {
    $part = aPart($this->provider);
    $server = aServer($this->provider, 'web-3');
    $this->parts->fit($part, $server, $this->admin);

    HardwarePart::factory()->outOfWarranty()->of(PartKind::Optic)
        ->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/infrastructure/parts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Dcim/Parts')
            ->has('parts.data', 2)
            // Oldest warranty first: the ones about to lapse are what this
            // screen is opened for.
            ->where('parts.data.0.outOfWarranty', true)
            ->where('parts.data.1.server', 'web-3')
            ->has('parts.links')
            ->where('can.manage', true));
});

it('filters by kind', function (): void {
    aPart($this->provider);
    HardwarePart::factory()->of(PartKind::Optic)->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/infrastructure/parts?kind=optic')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('parts.data', 1)
            ->where('parts.data.0.kind', PartKind::Optic->value));
});

it('drives the detail, with everywhere the part has been', function (): void {
    $part = aPart($this->provider);
    $first = aServer($this->provider, 'web-3');
    $second = aServer($this->provider, 'web-7');

    $this->parts->fit($part, $first, $this->admin);
    $this->parts->fit($part, $second, $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->get("/admin/infrastructure/parts/{$part->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Dcim/Part')
            ->has('history', 2)
            // Newest first, which is where somebody looks.
            ->where('history.0.server', 'web-7')
            ->where('history.0.removedAt', null)
            ->where('history.1.server', 'web-3'));
});

/**
 * A part whose warranty ran out last year is exactly the one an operator
 * needs to record, so the date is not validated as being in the future.
 */
it('records a part whose warranty has already run out', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/infrastructure/parts', [
            'kind' => PartKind::Disk->value,
            'serial' => 'S123',
            'warranty_until' => CarbonImmutable::now()->subYear()->toDateString(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(HardwarePart::query()->sole()->isOutOfWarranty())->toBeTrue();
});

it('records a part with nothing but a kind', function (): void {
    // Every other field is optional, and `validate()` returns only what was
    // submitted — a field the form left empty is absent rather than null.
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/infrastructure/parts', ['kind' => PartKind::Other->value])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(HardwarePart::query()->sole()->kind)->toBe(PartKind::Other);
});

it('refuses a part to somebody who may only look', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/infrastructure/parts')
        ->assertOk();

    $this->actingAs($agent->fresh(), 'staff')
        ->post('/admin/infrastructure/parts', ['kind' => PartKind::Disk->value])
        ->assertForbidden();

    expect(HardwarePart::query()->count())->toBe(0);
});

it('refuses the screen to somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/infrastructure/parts')
        ->assertForbidden();
});

/** Every kind an operator reads is named, in both languages. */
it('names every part kind in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (PartKind::cases() as $kind) {
            expect(__($kind->labelKey()))->not->toBe($kind->labelKey());
        }
    }

    app()->setLocale('en');
});
