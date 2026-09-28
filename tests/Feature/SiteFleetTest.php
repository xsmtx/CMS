<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Application\Reliability\EvaluateAlertRule;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\Sites\ComponentKind;
use App\Domain\Infrastructure\Sites\SiteComponent;
use App\Domain\Infrastructure\Sites\SiteInstallation;
use App\Domain\Infrastructure\Sites\SiteVersion;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Reliability\AlertComparison;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertSubject;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Http;

/**
 * The WordPress fleet (§18), driven through the real WP Toolkit package over
 * faked HTTP.
 *
 * **Out of date and vulnerable are different facts, and this file exists to
 * keep them apart.** Three releases behind with nothing said against it is
 * housekeeping somebody does on a Thursday; a published advisory is tonight.
 * A single "needs attention" figure would let four hundred of the first kind
 * hide the one of the second — and an operator who learned to ignore that
 * list would be right to.
 *
 * The other half is the one that would be a disaster to get wrong: a site
 * nothing looked at must never be reported as clean. A `vulnerable` of zero
 * on an installation with no vulnerability data is a clean bill of health
 * somebody would put on a slide.
 */
beforeEach(function (): void {
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

    app(ModuleCatalogue::class)->forget();
    app(ActiveModules::class)->forget();
});

/**
 * What the fake panel is currently reporting.
 *
 * A static rather than a second `Http::fake()` call: faking twice adds a stub
 * rather than replacing the first, so a test that "changes the answer"
 * changes nothing.
 */
final class PanelState
{
    /** @var list<array<string, mixed>> */
    public static array $instances = [];

    /** @var list<array<string, mixed>> */
    public static array $plugins = [];

    /** @var list<array<string, mixed>> */
    public static array $themes = [];

    public static int $status = 200;
}

/**
 * @param  list<array<string, mixed>>  $instances
 * @param  list<array<string, mixed>>  $plugins
 * @param  list<array<string, mixed>>  $themes
 */
function panelAnswers(array $instances, array $plugins = [], array $themes = [], int $status = 200): void
{
    PanelState::$instances = $instances;
    PanelState::$plugins = $plugins;
    PanelState::$themes = $themes;
    PanelState::$status = $status;

    Http::fake([
        'panel.test/modules/wp-toolkit/api/v1/instances*' => fn () => Http::response(
            PanelState::$instances,
            PanelState::$status,
        ),
        'panel.test/modules/wp-toolkit/api/v1/plugins*' => fn () => Http::response(
            PanelState::$plugins,
            PanelState::$status,
        ),
        'panel.test/modules/wp-toolkit/api/v1/themes*' => fn () => Http::response(
            PanelState::$themes,
            PanelState::$status,
        ),
        'panel.test/*' => fn () => Http::response([]),
    ]);
}

function enablePanel(StaffUser $actor, ?string $serverNode = null): void
{
    $record = app(InstallModule::class)->handle('sites-wptoolkit', $actor);
    $manifest = app(ModuleCatalogue::class)->find('sites-wptoolkit');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        array_filter([
            'base_url' => 'https://panel.test',
            'server_node' => $serverNode,
            'verify_tls' => false,
        ], static fn (mixed $value): bool => $value !== null),
        $actor,
    );

    app(EnableModule::class)->handle($record->fresh(), $actor);
    app(ActiveModules::class)->forget();
}

function discoverSites(): RunSummary
{
    return app(TaskRegistry::class)->resolve(AutomationTask::Sites)->handle();
}

function siteRule(string $organizationId, AlertSubject $subject, ?float $threshold = null): AlertRule
{
    return AlertRule::query()->create([
        'organization_id' => $organizationId,
        'name' => 'Sites',
        'subject' => $subject,
        'severity' => AlertSeverity::Warning,
        'comparison' => $threshold === null ? null : AlertComparison::Above,
        // Stored in parts per million, like every threshold here.
        'threshold_ppm' => $threshold === null ? null : (int) ($threshold * 1_000_000),
        'for_minutes' => 0,
        'enabled' => true,
        'notify' => false,
    ]);
}

it('is behind only when it knows what the latest version is', function (): void {
    expect(SiteVersion::isBehind('6.4.2', '6.6.1'))->toBeTrue()
        ->and(SiteVersion::isBehind('6.6.1', '6.6.1'))->toBeFalse()
        // Not knowing is not being up to date, and it is not being behind
        // either. Treating the absence as current would make a fleet of
        // unreadable sites look perfectly maintained.
        ->and(SiteVersion::isBehind('6.4.2', null))->toBeFalse()
        ->and(SiteVersion::isBehind(null, '6.6.1'))->toBeFalse()
        ->and(SiteVersion::isBehind('1.0.0-beta2', '1.0.0'))->toBeTrue();
});

it('counts the core apart from the components', function (): void {
    $site = new SiteInstallation(
        key: 'i1',
        url: 'https://shop.example',
        version: '6.4.2',
        latestVersion: '6.6.1',
        components: [
            new SiteComponent(ComponentKind::Plugin, 'woocommerce', 'WooCommerce', '8.1.0', '9.2.0'),
            new SiteComponent(ComponentKind::Theme, 'storefront', 'Storefront', '4.5.0', '4.5.0'),
        ],
    );

    expect($site->isCoreOutdated())->toBeTrue()
        ->and($site->outdatedComponents())->toBe(1)
        ->and($site->vulnerableComponents())->toBe(0)
        // Nothing looked, which is not the same as nothing found.
        ->and($site->knowsAboutVulnerabilities())->toBeFalse();
});

it('writes each site into the graph with its versions', function (): void {
    panelAnswers(
        [[
            'id' => 'i1',
            'siteUrl' => 'https://shop.example',
            'version' => '6.4.2',
            'availableVersion' => '6.6.1',
            'phpVersion' => '8.2.18',
            'wpPath' => '/var/www/vhosts/shop.example/httpdocs',
        ]],
        [[
            'instanceId' => 'i1',
            'slug' => 'woocommerce',
            'title' => 'WooCommerce',
            'version' => '8.1.0',
            'availableVersion' => '9.2.0',
            'status' => 'active',
        ]],
        [[
            'instanceId' => 'i1',
            'slug' => 'storefront',
            'title' => 'Storefront',
            'version' => '4.5.0',
            'availableVersion' => '4.5.0',
            'status' => 'inactive',
        ]],
    );

    enablePanel($this->admin);

    expect(discoverSites()->changed)->toBe(1);

    $node = ResourceNode::query()->where('kind', 'site')->sole();

    expect($node->label)->toBe('https://shop.example')
        // The toolkit's own id, never the URL: an address moves when somebody
        // finishes a migration.
        ->and($node->node_key)->toBe('wptoolkit/i1')
        ->and($node->attributes['version'])->toBe('6.4.2')
        ->and($node->attributes['php_version'])->toBe('8.2.18')
        ->and($node->attributes['core_outdated'])->toBeTrue()
        // Three facts, kept apart. A deactivated theme still counts as a
        // component; it is a directory of PHP on somebody's account.
        ->and($node->attributes['components'])->toBe(2)
        ->and($node->attributes['outdated'])->toBe(1)
        ->and($node->attributes['vulnerable'])->toBe(0);
});

it('leaves a site nothing looked at marked as unexamined', function (): void {
    panelAnswers(
        [['id' => 'i1', 'siteUrl' => 'https://shop.example', 'version' => '6.6.1']],
        // No `vulnerabilities` key at all, which is an older toolkit — and
        // must not read as "nothing is wrong with it".
        [['instanceId' => 'i1', 'slug' => 'akismet', 'version' => '5.3']],
    );

    enablePanel($this->admin);
    discoverSites();

    $node = ResourceNode::query()->where('kind', 'site')->sole();

    expect($node->attributes['vulnerable'])->toBe(0)
        ->and($node->attributes['vulnerability_data'])->toBeFalse();
});

it('records an advisory as a reference rather than a copy', function (): void {
    panelAnswers(
        [['id' => 'i1', 'siteUrl' => 'https://shop.example', 'version' => '6.6.1']],
        [[
            'instanceId' => 'i1',
            'slug' => 'leaky',
            'version' => '1.2',
            'vulnerabilities' => [['id' => 'WP-2026-1', 'url' => 'https://advisories.test/WP-2026-1']],
        ], [
            'instanceId' => 'i1',
            'slug' => 'fine',
            'version' => '3.0',
            'vulnerabilities' => [],
        ]],
    );

    enablePanel($this->admin);
    discoverSites();

    $node = ResourceNode::query()->where('kind', 'site')->sole();

    expect($node->attributes['vulnerable'])->toBe(1)
        ->and($node->attributes['vulnerability_data'])->toBeTrue()
        // And out of date is still zero: the two never merge.
        ->and($node->attributes['outdated'])->toBe(0);
});

it('hangs a site off the machine an operator named', function (): void {
    panelAnswers([['id' => 'i1', 'siteUrl' => 'https://shop.example', 'version' => '6.6.1']]);

    $server = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'server',
        nodeKey: 'web-7',
        label: 'web-7',
    );

    enablePanel($this->admin, serverNode: 'web-7');
    discoverSites();

    $site = ResourceNode::query()->where('kind', 'site')->sole();

    // Containment points downward, which is the graph's privacy rule: a
    // server contains a site, never the other way round.
    $edge = ResourceEdge::query()
        ->where('from_node_id', $server->id)
        ->where('to_node_id', $site->id)
        ->sole();

    expect($edge->relation)->toBe(Relation::Contains);
});

it('retires a site the panel stopped reporting', function (): void {
    panelAnswers([
        ['id' => 'i1', 'siteUrl' => 'https://one.example', 'version' => '6.6.1'],
        ['id' => 'i2', 'siteUrl' => 'https://two.example', 'version' => '6.6.1'],
    ]);

    enablePanel($this->admin);
    discoverSites();

    expect(ResourceNode::query()->where('kind', 'site')->whereNull('retired_at')->count())->toBe(2);

    panelAnswers([['id' => 'i1', 'siteUrl' => 'https://one.example', 'version' => '6.6.1']]);
    discoverSites();

    expect(ResourceNode::query()->where('kind', 'site')->whereNull('retired_at')->count())->toBe(1);
});

/**
 * A panel that is down must not empty the fleet. An adapter returning `[]`
 * would retire every site it has ever written, clear the alert about the
 * vulnerable plugin, and put it all back the next morning.
 */
it('fails the run rather than emptying the fleet when the panel is down', function (): void {
    panelAnswers([['id' => 'i1', 'siteUrl' => 'https://one.example', 'version' => '6.6.1']]);

    enablePanel($this->admin);
    discoverSites();

    panelAnswers([], status: 503);

    $summary = discoverSites();

    expect($summary->failed)->toBe(1)
        ->and(ResourceNode::query()->where('kind', 'site')->whereNull('retired_at')->count())->toBe(1);
});

it('says a panel has no toolkit rather than that it did not answer', function (): void {
    panelAnswers([], status: 404);

    enablePanel($this->admin);

    $summary = discoverSites();

    expect($summary->failed)->toBe(1)
        ->and($summary->items[0]->message)->toContain('no site toolkit');
});

it('raises when too much of a site is out of date', function (): void {
    panelAnswers(
        [['id' => 'i1', 'siteUrl' => 'https://shop.example', 'version' => '6.4.2', 'availableVersion' => '6.6.1']],
        [
            ['instanceId' => 'i1', 'slug' => 'a', 'version' => '1.0', 'availableVersion' => '2.0'],
            ['instanceId' => 'i1', 'slug' => 'b', 'version' => '1.0', 'availableVersion' => '2.0'],
        ],
    );

    enablePanel($this->admin);
    discoverSites();

    $rule = siteRule($this->provider->id, AlertSubject::SiteUpdates, threshold: 2);

    expect(app(EvaluateAlertRule::class)->handle($rule)['raised'])->toBe(1);

    $alert = Alert::query()->sole();

    // Two plugins and the core. The sentence says the core is among them,
    // which is the one component whose age says something about every other.
    expect($alert->observed)->toContain('the core');
});

it('raises on an advisory whatever the version numbers say', function (): void {
    panelAnswers(
        [['id' => 'i1', 'siteUrl' => 'https://shop.example', 'version' => '6.6.1', 'availableVersion' => '6.6.1']],
        [[
            'instanceId' => 'i1',
            'slug' => 'leaky',
            'version' => '1.2',
            'availableVersion' => '1.2',
            'vulnerabilities' => [['id' => 'WP-2026-1']],
        ]],
    );

    enablePanel($this->admin);
    discoverSites();

    $updates = siteRule($this->provider->id, AlertSubject::SiteUpdates, threshold: 0);
    $advisories = siteRule($this->provider->id, AlertSubject::SiteVulnerability);

    // Nothing is out of date at all, and the site is still an incident.
    expect(app(EvaluateAlertRule::class)->handle($updates)['raised'])->toBe(0)
        ->and(app(EvaluateAlertRule::class)->handle($advisories)['raised'])->toBe(1);
});

it('says nothing about a site nothing looked at', function (): void {
    panelAnswers(
        [['id' => 'i1', 'siteUrl' => 'https://shop.example', 'version' => '6.6.1']],
        [['instanceId' => 'i1', 'slug' => 'akismet', 'version' => '5.3']],
    );

    enablePanel($this->admin);
    discoverSites();

    $rule = siteRule($this->provider->id, AlertSubject::SiteVulnerability);

    // No observation at all, rather than a clean one: this platform must not
    // assert that a site is safe because nothing checked.
    expect(app(EvaluateAlertRule::class)->handle($rule))
        ->toBe(['raised' => 0, 'kept' => 0, 'cleared' => 0]);
});
