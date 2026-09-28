<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Network\AssignAddress;
use App\Application\Security\RecordListings;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Infrastructure\Mail\BlocklistListing;
use App\Domain\Network\AddressState;
use App\Domain\Network\IpAddress;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Reliability\AlertComparison;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertState;
use App\Domain\Reliability\AlertSubject;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpPool;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Infrastructure\Security\Models\ReputationListing;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Sending reputation (§13).
 *
 * **Raise and clear, like an alert and like a zone finding.** A listing is
 * true for a while and then stops being true, and the cleared row is the only
 * record that it ever happened — which is what somebody reads when a customer
 * asks why their mail was slow in March.
 *
 * **Attribution is taken once, when the listing is first seen.** The same
 * walk an abuse report and a DDoS event use, and the same reason: an address
 * handed to somebody else since Tuesday must not make Tuesday's listing
 * theirs.
 *
 * Nothing here delists, and there is no test asserting that it does. Asking a
 * blocklist to lift a listing is a form with a human on the other end.
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

    $this->record = app(RecordListings::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** A customer with one active service. */
function listedCustomer(string $company): Customer
{
    return app(OrganizationContext::class)->withoutBoundary(static function () use ($company): Customer {
        $customer = Customer::factory()->create(['company_name' => $company]);

        Service::factory()->create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'status' => ServiceStatus::Active->value,
        ]);

        return $customer;
    });
}

/** An address inside a prefix this installation records. */
function listedAddress(Organization $provider, string $cidr, string $address): IpAddressRecord
{
    $pool = IpPool::factory()->create(['organization_id' => $provider->id]);
    $prefix = IpPrefixRecord::factory()->inPool($pool)->of($cidr)->create();

    $record = IpAddressRecord::within($prefix, IpAddress::parse($address), AddressState::Available);
    $record->save();

    return $record;
}

function spamhaus(string $address, ?string $reason = null, ?string $url = null): BlocklistListing
{
    return new BlocklistListing($address, 'zen.spamhaus.org', $reason, $url);
}

it('records a listing and attributes it to whoever holds the address', function (): void {
    $customer = listedCustomer('The one who sent it');
    $record = listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');
    $service = $customer->services()->sole();

    app(AssignAddress::class)->handle($record, $service);

    $outcome = $this->record->handle($this->provider->id, 'test-reputation', [
        spamhaus('198.51.100.7', 'Listed for outbound spam', 'https://example.net/removal'),
    ]);

    $listing = ReputationListing::query()->sole();

    expect($outcome)->toBe(['raised' => 1, 'kept' => 0, 'cleared' => 0, 'skipped' => 0])
        ->and($listing->address)->toBe('198.51.100.7')
        ->and($listing->list)->toBe('zen.spamhaus.org')
        // The blocklist's own words, kept as they were written.
        ->and($listing->reason)->toBe('Listed for outbound spam')
        // The way out, which is the field an operator actually needs next.
        ->and($listing->delist_url)->toBe('https://example.net/removal')
        ->and($listing->customer_id)->toBe($customer->id)
        ->and($listing->service_id)->toBe($service->id);
});

it('keeps a listing that is still true, and refreshes what the list says', function (): void {
    CarbonImmutable::setTestNow('2026-09-20 09:00:00');

    listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');

    $this->record->handle($this->provider->id, 'test-reputation', [
        spamhaus('198.51.100.7', 'First reason', 'https://example.net/one'),
    ]);

    CarbonImmutable::setTestNow('2026-09-22 09:00:00');

    $outcome = $this->record->handle($this->provider->id, 'test-reputation', [
        spamhaus('198.51.100.7', 'Second reason', 'https://example.net/two'),
    ]);

    $listing = ReputationListing::query()->sole();

    expect($outcome['raised'])->toBe(0)
        ->and($outcome['kept'])->toBe(1)
        ->and($listing->first_seen_at->toDateString())->toBe('2026-09-20')
        ->and($listing->last_seen_at->toDateString())->toBe('2026-09-22')
        // A list changes its own words and its own form, and the link
        // somebody follows should be the one it currently publishes.
        ->and($listing->reason)->toBe('Second reason')
        ->and($listing->delist_url)->toBe('https://example.net/two');
});

/**
 * Clearing is the half people forget. A screen that only ever grew would be
 * full of listings somebody got lifted in March.
 */
it('clears a listing that has been lifted, and keeps the row', function (): void {
    listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');

    $this->record->handle($this->provider->id, 'test-reputation', [spamhaus('198.51.100.7')]);

    $outcome = $this->record->handle($this->provider->id, 'test-reputation', []);

    $listing = ReputationListing::query()->sole();

    expect($outcome['cleared'])->toBe(1)
        ->and($listing->cleared_at)->not->toBeNull()
        ->and($listing->cleared_token)->toBe($listing->id)
        ->and($listing->isOpen())->toBeFalse();
});

/**
 * MariaDB treats nulls in a unique index as distinct, which is why the token
 * exists at all: without it the key would allow two open rows and look as
 * though it were doing the work.
 */
it('lets the same address be listed again once it has been lifted', function (): void {
    listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');

    $this->record->handle($this->provider->id, 'test-reputation', [spamhaus('198.51.100.7')]);
    $this->record->handle($this->provider->id, 'test-reputation', []);
    $outcome = $this->record->handle($this->provider->id, 'test-reputation', [spamhaus('198.51.100.7')]);

    expect($outcome['raised'])->toBe(1)
        ->and(ReputationListing::query()->count())->toBe(2)
        ->and(ReputationListing::query()->open()->count())->toBe(1);
});

/** Any spelling of an address is the same address. */
it('treats two spellings of one address as one listing', function (): void {
    listedAddress($this->provider, '2001:db8::/64', '2001:db8::1');

    $this->record->handle($this->provider->id, 'test-reputation', [spamhaus('2001:db8::1')]);

    $outcome = $this->record->handle($this->provider->id, 'test-reputation', [
        spamhaus('2001:0db8:0000:0000:0000:0000:0000:0001'),
    ]);

    expect($outcome['raised'])->toBe(0)
        ->and($outcome['kept'])->toBe(1)
        ->and(ReputationListing::query()->count())->toBe(1);
});

/**
 * The listing is about what happened while the first customer held it. An
 * address that moves afterwards must not take the blame with it.
 */
it('does not re-attribute a listing when the address later moves', function (): void {
    CarbonImmutable::setTestNow('2026-09-20 09:00:00');

    $first = listedCustomer('The one who sent it');
    $second = listedCustomer('The innocent one');
    $record = listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');

    $assign = app(AssignAddress::class);
    $assign->handle($record, $first->services()->sole());

    $this->record->handle($this->provider->id, 'test-reputation', [spamhaus('198.51.100.7')]);

    CarbonImmutable::setTestNow('2026-09-25 09:00:00');
    $assign->release($record->fresh(), quarantine: false);
    $assign->handle($record->fresh(), $second->services()->sole());

    $this->record->handle($this->provider->id, 'test-reputation', [spamhaus('198.51.100.7')]);

    expect(ReputationListing::query()->sole()->customer_id)->toBe($first->id);
});

/**
 * A row whose address cannot be compared is a row nothing can attribute and
 * nothing can clear. Counted and dropped, like a metric core has no kind for.
 */
it('skips an address it cannot parse rather than storing it', function (): void {
    $outcome = $this->record->handle($this->provider->id, 'test-reputation', [
        spamhaus('not an address'),
        spamhaus('198.51.100.7'),
    ]);

    expect($outcome['skipped'])->toBe(1)
        ->and($outcome['raised'])->toBe(1)
        ->and(ReputationListing::query()->sole()->address)->toBe('198.51.100.7');
});

/**
 * Two reputation sources watching one address is a real configuration, and
 * they disagree often. One clearing must not close the other's finding.
 */
it('keeps one source’s listings apart from another’s', function (): void {
    listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');

    $this->record->handle($this->provider->id, 'first-source', [spamhaus('198.51.100.7')]);
    $this->record->handle($this->provider->id, 'second-source', [spamhaus('198.51.100.7')]);

    $outcome = $this->record->handle($this->provider->id, 'first-source', []);

    expect($outcome['cleared'])->toBe(1)
        ->and(ReputationListing::query()->open()->sole()->source)->toBe('second-source');
});

/**
 * One address on four lists is four rows, because four delisting forms have
 * to be filled in. Clearing one of them clears only that one.
 */
it('keeps one list apart from another', function (): void {
    listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');

    $this->record->handle($this->provider->id, 'test-reputation', [
        spamhaus('198.51.100.7'),
        new BlocklistListing('198.51.100.7', 'bl.example.net'),
    ]);

    $outcome = $this->record->handle($this->provider->id, 'test-reputation', [
        spamhaus('198.51.100.7'),
    ]);

    expect($outcome['cleared'])->toBe(1)
        ->and(ReputationListing::query()->open()->sole()->list)->toBe('zen.spamhaus.org');
});

/**
 * The worst thing this sweep could do is decide an installation with no
 * reputation source has had every listing lifted overnight.
 */
it('clears nothing when no reputation source is configured', function (): void {
    listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');

    $this->record->handle($this->provider->id, 'test-reputation', [spamhaus('198.51.100.7')]);

    app(TaskRegistry::class)->resolve(AutomationTask::Reputation)->handle();

    expect(ReputationListing::query()->open()->count())->toBe(1);
});

it('raises an alert on a listing the operator has decided is too old', function (): void {
    CarbonImmutable::setTestNow('2026-09-20 09:00:00');

    listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');
    $this->record->handle($this->provider->id, 'test-reputation', [spamhaus('198.51.100.7')]);

    AlertRule::factory()->create([
        'organization_id' => $this->provider->id,
        'subject' => AlertSubject::ReputationListing,
        'target' => null,
        'comparison' => AlertComparison::Above,
        // Two days, which is the operator's number. Core ships no rules.
        'threshold_ppm' => 2_000_000,
        'for_minutes' => 0,
        'severity' => AlertSeverity::Critical,
        'enabled' => true,
        'notify' => false,
    ]);

    CarbonImmutable::setTestNow('2026-09-25 09:00:00');

    app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    $alert = Alert::query()->sole();

    expect($alert->state)->toBe(AlertState::Raised)
        ->and($alert->subject_label)->toBe('198.51.100.7 — zen.spamhaus.org');
});

/** A lifted listing is simply absent, which is how the alert clears. */
it('does not alert on a listing that has been lifted', function (): void {
    CarbonImmutable::setTestNow('2026-09-20 09:00:00');

    listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');
    $this->record->handle($this->provider->id, 'test-reputation', [spamhaus('198.51.100.7')]);

    CarbonImmutable::setTestNow('2026-09-25 09:00:00');
    $this->record->handle($this->provider->id, 'test-reputation', []);

    AlertRule::factory()->create([
        'organization_id' => $this->provider->id,
        'subject' => AlertSubject::ReputationListing,
        'target' => null,
        'comparison' => AlertComparison::Above,
        'threshold_ppm' => 2_000_000,
        'for_minutes' => 0,
        'severity' => AlertSeverity::Critical,
        'enabled' => true,
        'notify' => false,
    ]);

    app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    expect(Alert::query()->count())->toBe(0);
});

it('drives the screen, whoever we have to tell first', function (): void {
    $customer = listedCustomer('Somebody to telephone');
    $record = listedAddress($this->provider, '198.51.100.0/24', '198.51.100.7');
    app(AssignAddress::class)->handle($record, $customer->services()->sole());

    listedAddress($this->provider, '203.0.113.0/24', '203.0.113.9');

    $this->record->handle($this->provider->id, 'test-reputation', [
        // Ours, nobody's to telephone about.
        spamhaus('203.0.113.9'),
        spamhaus('198.51.100.7', 'Outbound spam', 'https://example.net/removal'),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/security/reputation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Security/Reputation')
            ->has('listings.data', 2)
            ->where('counts.listed', 2)
            // One address on one list each, and one customer to tell.
            ->where('counts.addresses', 2)
            ->where('counts.customers', 1)
            // Attributed first: somebody to tell today, before something to
            // fix on our own time.
            ->where('listings.data.0.customerId', $customer->id)
            ->where('listings.data.0.delistUrl', 'https://example.net/removal')
            ->has('listings.links'));
});

it('refuses somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/security/reputation')
        ->assertForbidden();
});
