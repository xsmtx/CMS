<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Application\Network\ApplyNetworkChange;
use App\Application\Network\ConfigurationDiff;
use App\Application\Network\DecideNetworkChange;
use App\Application\Network\RequestNetworkChange;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Network\Exceptions\ChangeRefused;
use App\Domain\Network\NetworkChangeState;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Network\Models\NetworkChange;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Http;

/**
 * The guarded configuration workflow (`phase-c-plan.md` §6).
 *
 * Every refusal below is a step §6 names, and the order is the whole point:
 * a firewall reloading is the most consequential thing this platform can do,
 * and the only thing that makes it acceptable is that it cannot happen
 * without a reason, a second person, a backup and a device that is still
 * where the diff left it.
 *
 * Driven through the real FortiGate package over faked HTTP, for the reason
 * `AdapterHealthSweepTest` gives: `ActiveModules` is final on purpose and a
 * fake of it would be a fake of the thing under test.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->requester = StaffUser::factory()->create(['name' => 'Asked']);
    $this->requester->assignRole(SystemRole::Administrator);
    $this->requester = $this->requester->fresh();

    $this->approver = StaffUser::factory()->create(['name' => 'Agreed']);
    $this->approver->assignRole(SystemRole::Administrator);
    $this->approver = $this->approver->fresh();

    app(ModuleCatalogue::class)->forget();
    app(ActiveModules::class)->forget();

    $this->device = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'network_device',
        nodeKey: 'fw1.dc2',
        label: 'fw1',
        source: 'topology:fortigate',
    );
});

/**
 * What the fake FortiGate currently has on it.
 *
 * A static rather than a second `Http::fake()` call: faking twice adds a stub
 * rather than replacing the first, so a test that "changed the answer" would
 * change nothing — and this whole file is about the device changing under a
 * change that was agreed to.
 */
final class FortigateConfig
{
    public static string $text = "config system global\n    set hostname \"fw1\"\nend\n";

    /** Whether the device keeps what it is given, or quietly does not. */
    public static bool $keeps = true;

    /** Whether it is answering at all. */
    public static bool $reachable = true;

    public static int $writes = 0;
}

function fortigateHolds(string $text, bool $keeps = true): void
{
    FortigateConfig::$text = $text;
    FortigateConfig::$keeps = $keeps;
    FortigateConfig::$reachable = true;
    FortigateConfig::$writes = 0;

    /*
     * One `Http::fake()` for the whole file, and the state is held in statics.
     * Faking twice *adds* a stub rather than replacing the first, so a test
     * that "made the device stop answering" would change nothing — which is
     * the trap `AdapterHealthSweepTest` already names.
     */
    Http::fake([
        'fw1.test/api/v2/monitor/system/config/backup*' => fn () => FortigateConfig::$reachable
            ? Http::response(FortigateConfig::$text)
            : Http::response('gone', 503),
        'fw1.test/api/v2/monitor/system/config/restore*' => function ($request) {
            FortigateConfig::$writes++;

            if (FortigateConfig::$keeps) {
                // What the adapter actually attached, read back out of the
                // multipart body: the device keeps exactly what it was sent,
                // so a test of the workflow is also a test of the request.
                foreach ($request->data() as $part) {
                    if (($part['name'] ?? null) === 'file') {
                        FortigateConfig::$text = (string) ($part['contents'] ?? '');
                    }
                }
            }

            return Http::response(['status' => 'success']);
        },
        'fw1.test/*' => fn () => Http::response(['results' => []]),
    ]);
}

function enableFortigateWriter(StaffUser $actor, bool $writes): void
{
    $record = app(InstallModule::class)->handle('network-fortigate', $actor);
    $manifest = app(ModuleCatalogue::class)->find('network-fortigate');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['base_url' => 'https://fw1.test', 'vdom' => 'root', 'verify_tls' => false],
        $actor,
    );

    app(EnableModule::class)->handle($record->fresh(), $actor);
    app(ActiveModules::class)->forget();

    if (! $writes) {
        return;
    }

    // What the row decides, not what the package declares. An adapter may say
    // it can change a firewall; this installation says whether it may.
    ResourceAdapter::query()->updateOrCreate(
        ['organization_id' => test()->provider->id, 'adapter_key' => 'fortigate'],
        ['name' => 'FortiGate', 'vendor' => 'Fortinet', 'enabled' => true, 'writes_enabled' => true],
    );
}

function requestChange(string $intended = "config system global\n    set hostname \"fw2\"\nend\n"): NetworkChange
{
    return app(RequestNetworkChange::class)->handle(
        device: test()->device,
        requester: test()->requester,
        summary: 'Rename the box',
        reason: 'It moved rack and the name is wrong on every graph.',
        intended: $intended,
    );
}

it('reads the device and stores the diff somebody will agree to', function (): void {
    fortigateHolds("config system global\n    set hostname \"fw1\"\nend\n");
    enableFortigateWriter($this->requester, writes: false);

    $change = requestChange();

    expect($change->state)->toBe(NetworkChangeState::AwaitingApproval)
        ->and($change->requires_approval)->toBeTrue()
        ->and($change->diff)->toContain('-    set hostname "fw1"')
        ->and($change->diff)->toContain('+    set hostname "fw2"')
        // The fingerprint of the box as it was: what makes the check before
        // the apply cheap, and what makes it possible at all.
        ->and($change->fingerprint_before)->toHaveLength(64);
});

/**
 * A change one person both asked for and agreed to is a change nobody agreed
 * to. Enforced here rather than by the permission, which can say who may
 * approve and cannot say whose change.
 */
it('refuses a decision from the person who asked', function (): void {
    fortigateHolds("a\n");
    enableFortigateWriter($this->requester, writes: false);

    $change = requestChange();

    expect(fn () => app(DecideNetworkChange::class)->approve($change, $this->requester))
        ->toThrow(ChangeRefused::class, 'somebody other than');

    expect($change->fresh()?->state)->toBe(NetworkChangeState::AwaitingApproval);
});

it('will not apply a change nobody has approved', function (): void {
    fortigateHolds("a\n");
    enableFortigateWriter($this->requester, writes: true);

    $change = requestChange();

    expect(fn () => app(ApplyNetworkChange::class)->handle($change))
        ->toThrow(ChangeRefused::class, 'awaiting_approval');

    expect(FortigateConfig::$writes)->toBe(0);
});

/**
 * The package declares `DeviceConfigWrite` and the row decides. An adapter an
 * operator has not enabled writes on is *absent* rather than refused, which
 * is what stops a screen offering a button the platform would decline.
 */
it('will not apply through an adapter nobody enabled writes on', function (): void {
    fortigateHolds("a\n");
    enableFortigateWriter($this->requester, writes: false);

    $change = requestChange();
    app(DecideNetworkChange::class)->approve($change, $this->approver);

    $applied = app(ApplyNetworkChange::class)->handle($change->fresh());

    expect($applied->state)->toBe(NetworkChangeState::Failed)
        ->and($applied->result)->toContain('permitted to change')
        ->and(FortigateConfig::$writes)->toBe(0);
});

it('backs up, applies and reads it back', function (): void {
    $before = "config system global\n    set hostname \"fw1\"\nend\n";
    $after = "config system global\n    set hostname \"fw2\"\nend\n";

    fortigateHolds($before);
    enableFortigateWriter($this->requester, writes: true);

    $change = requestChange($after);
    app(DecideNetworkChange::class)->approve($change, $this->approver, 'Looks right.');

    $applied = app(ApplyNetworkChange::class)->handle($change->fresh());

    expect($applied->state)->toBe(NetworkChangeState::Completed)
        ->and($applied->result)->toContain('verified')
        // The backup is taken before the write and kept on the record, so a
        // rollback does not have to ask a device that may be unreachable.
        ->and($applied->backup)->toBe($before)
        ->and($applied->backed_up_at)->not->toBeNull()
        ->and($applied->applied_at)->not->toBeNull()
        ->and(FortigateConfig::$writes)->toBe(1)
        ->and(FortigateConfig::$text)->toBe($after);
});

/**
 * The reason all of this exists. A diff somebody approved an hour ago is a
 * diff against a device somebody else may have edited since, and applying it
 * would silently revert their work.
 */
it('refuses when the device has moved since the diff was agreed to', function (): void {
    fortigateHolds("config system global\n    set hostname \"fw1\"\nend\n");
    enableFortigateWriter($this->requester, writes: true);

    $change = requestChange();
    app(DecideNetworkChange::class)->approve($change, $this->approver);

    // Somebody else edits the box between the approval and the apply.
    FortigateConfig::$text = "config system global\n    set hostname \"fw1\"\n    set timezone 04\nend\n";

    $applied = app(ApplyNetworkChange::class)->handle($change->fresh());

    expect($applied->state)->toBe(NetworkChangeState::Failed)
        ->and($applied->result)->toContain('not the one this change was reviewed against')
        ->and(FortigateConfig::$writes)->toBe(0);
});

/**
 * A backup that failed is a change that does not happen. The order is not a
 * suggestion.
 */
it('applies nothing when the backup cannot be taken', function (): void {
    enableFortigateWriter($this->requester, writes: true);

    fortigateHolds("a\n");
    $change = requestChange();
    app(DecideNetworkChange::class)->approve($change, $this->approver);

    // The device stops answering between the approval and the apply.
    FortigateConfig::$reachable = false;

    $applied = app(ApplyNetworkChange::class)->handle($change->fresh());

    expect($applied->state)->toBe(NetworkChangeState::Failed)
        ->and($applied->result)->toContain('could not be backed up')
        ->and($applied->backup)->toBeNull();
});

/**
 * A device that accepted a configuration and did not keep it is the failure
 * worth catching, and no adapter can report it — so verification is a second
 * read, and a failed verification puts the backup back.
 */
it('rolls back when the device did not keep what it was given', function (): void {
    $before = "config system global\n    set hostname \"fw1\"\nend\n";

    fortigateHolds($before, keeps: false);
    enableFortigateWriter($this->requester, writes: true);

    $change = requestChange();
    app(DecideNetworkChange::class)->approve($change, $this->approver);

    $applied = app(ApplyNetworkChange::class)->handle($change->fresh());

    // `rolled_back`, not `failed`: an operator arriving at three in the
    // morning needs to know whether the box is where it started.
    expect($applied->state)->toBe(NetworkChangeState::RolledBack)
        ->and($applied->result)->toContain('backup was put back')
        // Twice: the apply, and the restore.
        ->and(FortigateConfig::$writes)->toBe(2);
});

it('lets the requester withdraw it, and says so differently from a refusal', function (): void {
    fortigateHolds("a\n");
    enableFortigateWriter($this->requester, writes: false);

    $cancelled = app(DecideNetworkChange::class)->cancel(requestChange(), $this->requester);
    $rejected = app(DecideNetworkChange::class)->reject(requestChange(), $this->approver, 'Not this week.');

    expect($cancelled->state)->toBe(NetworkChangeState::Cancelled)
        ->and($rejected->state)->toBe(NetworkChangeState::Rejected)
        ->and($rejected->decided_by)->toBe($this->approver->id)
        ->and($rejected->decision_note)->toBe('Not this week.');
});

it('cannot be requested against a device nothing here may read', function (): void {
    // No module enabled at all.
    expect(fn (): NetworkChange => requestChange())
        ->toThrow(ChangeRefused::class, 'fw1.dc2');
});

it('writes a diff a person can read', function (): void {
    $diff = ConfigurationDiff::between("one\ntwo\nthree\n", "one\nthree\nfour\n");

    expect($diff)->toBe(" one\n-two\n three\n+four");

    // Line endings normalised first, or a device answering over a second
    // transport would look like every line had changed.
    expect(ConfigurationDiff::differ("one\r\ntwo\r\n", "one\ntwo\n"))->toBeFalse();
});
