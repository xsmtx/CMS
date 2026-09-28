<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\SiteProvider;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\Sites\SiteInstallation;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Asks every panel which web applications it is hosting, and writes them into
 * the graph (§18).
 *
 * **A site is a node and its plugin list is an attribute on it.** §14's rule
 * for the sixth time: the graph stores identity and relationships, and a
 * table of four hundred sites times sixty plugins, rewritten every day,
 * would be a time-series database nobody sized. What an operator is asked to
 * act on is a count and a version, and both fit on the node.
 *
 * **The counts are separate and stay separate.** `core_outdated`, `outdated`
 * and `vulnerable` are three facts, and a screen that added them into "needs
 * attention" would let four hundred cosmetic upgrades hide the one plugin
 * with a published advisory.
 *
 * **`vulnerability_data` records whether anything looked.** Without it a
 * `vulnerable` of zero reads as a clean fleet on an installation where no
 * vulnerability database is configured at all — which is the most dangerous
 * possible false comfort in this phase, because it is the one somebody would
 * put on a slide.
 *
 * **The parent edge is only drawn where the panel named the machine.** A
 * `Relation::Contains` from a server down to a site is what makes the impact
 * walk say which customer sites go with a machine; inventing one would put
 * somebody's shop under the wrong server on a guess.
 *
 * Daily. A plugin release is not an event anybody is woken for, and a fleet
 * of four hundred sites asked hourly is a panel's API budget spent on
 * nothing — the vulnerability half arrives with the advisory, not with the
 * sweep.
 */
final readonly class DiscoverSites implements AutomationRun
{
    private const string SiteKind = 'site';

    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private ResourceGraph $graph,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                foreach ($this->registry->all($organizationId) as $registered) {
                    $summary = $this->discoverFrom($summary, $organizationId, $registered);
                }
            }

            return $summary;
        });
    }

    private function discoverFrom(
        RunSummary $summary,
        string $organizationId,
        RegisteredAdapter $registered,
    ): RunSummary {
        $adapter = $registered->adapter();

        if (! $adapter instanceof SiteProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::SiteInventoryRead)) {
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        // Whether this adapter is allowed to tell us what is wrong with a
        // component, as opposed to what is installed. An operator with the
        // inventory read and not the vulnerability one gets versions and no
        // advisories, and the node says so rather than saying nothing is
        // wrong.
        $advisories = $registered->permitted()->has(Capability::SiteVulnerabilityRead);

        try {
            $sites = $adapter->sites();
        } catch (Throwable $exception) {
            return $summary->failing(new RunItem(
                ItemOutcome::Failed,
                ResourceAdapter::class,
                $registered->row->id,
                $registered->descriptor->name,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        $written = $this->write($organizationId, $registered, $sites, $advisories);

        return $summary->changing(new RunItem(
            ItemOutcome::Changed,
            ResourceAdapter::class,
            $registered->row->id,
            $registered->descriptor->name,
            sprintf(
                '%d sites, %d behind, %d with an advisory',
                count($sites),
                $written['behind'],
                $written['vulnerable'],
            ),
        ));
    }

    /**
     * @param  list<SiteInstallation>  $sites
     * @return array{behind: int, vulnerable: int}
     */
    private function write(
        string $organizationId,
        RegisteredAdapter $registered,
        array $sites,
        bool $advisories,
    ): array {
        $source = substr('sites:'.$registered->descriptor->key, 0, 48);

        $seen = [];
        $behind = 0;
        $vulnerable = 0;

        foreach ($sites as $site) {
            $outdated = $site->outdatedComponents();
            $withAdvisories = $advisories ? $site->vulnerableComponents() : 0;
            $coreOutdated = $site->isCoreOutdated();

            $node = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::SiteKind,
                // The panel's own identifier, never the URL: an address moves
                // when somebody finishes a migration, and a node keyed on it
                // would leave the old one behind as a site that vanished.
                nodeKey: $registered->descriptor->key.'/'.$site->key,
                label: $site->url,
                source: $source,
                attributes: array_filter([
                    'application' => $site->application,
                    'url' => $site->url,
                    'path' => $site->path,
                    'version' => $site->version,
                    'latest_version' => $site->latestVersion,
                    'php_version' => $site->phpVersion,
                    'components' => count($site->components),
                    'core_outdated' => $coreOutdated,
                    'outdated' => $outdated,
                    'vulnerable' => $withAdvisories,
                    // Whether anything looked at all. Without this, a zero
                    // above reads as a clean bill of health on an
                    // installation where no vulnerability database exists.
                    'vulnerability_data' => $advisories && $site->knowsAboutVulnerabilities(),
                ], static fn (mixed $value): bool => $value !== null),
            );

            $seen[$node->node_key] = true;

            if ($coreOutdated || $outdated > 0) {
                $behind++;
            }

            if ($withAdvisories > 0) {
                $vulnerable++;
            }

            $this->placeUnderDevice($organizationId, $node, $site, $source);
        }

        $this->retireDeparted($organizationId, $source, array_keys($seen));

        return ['behind' => $behind, 'vulnerable' => $vulnerable];
    }

    /**
     * Hang the site off the machine it runs on, where the panel said which.
     *
     * Containment points downward, which is the graph's privacy rule (ADR
     * 0043): a server contains a site, never the other way round, so a
     * customer cannot walk up from their shop to the machine it shares with
     * forty others.
     */
    private function placeUnderDevice(
        string $organizationId,
        ResourceNode $site,
        SiteInstallation $descriptor,
        string $source,
    ): void {
        if ($descriptor->deviceKey === null) {
            return;
        }

        $device = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('node_key', $descriptor->deviceKey)
            ->whereNull('retired_at')
            ->first();

        if (! $device instanceof ResourceNode) {
            return;
        }

        $this->graph->attach($device, $site, Relation::Contains, source: $source);
    }

    /**
     * @param  list<string>  $seen
     */
    private function retireDeparted(string $organizationId, string $source, array $seen): void
    {
        $departed = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->whereNull('retired_at')
            ->whereNotIn('node_key', $seen)
            ->get();

        foreach ($departed as $node) {
            $this->graph->retire($node);
        }
    }

    /**
     * @return list<string>
     */
    private function providerOrganizationIds(): array
    {
        return array_values(Organization::query()
            ->withoutGlobalScope('organization')
            ->whereIn('type', [
                OrganizationType::Provider->value,
                OrganizationType::Reseller->value,
            ])
            ->pluck('id')
            ->all());
    }
}
