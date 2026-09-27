<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Security\InspectZone;
use App\Application\Security\RecordZoneFindings;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\DnsProvider;
use App\Domain\Infrastructure\Dns\DnsZone;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Asks every DNS source about the zones this installation holds (§8).
 *
 * **Core asks about its own domains, not about the provider's zones.** A
 * provider may be authoritative for four thousand names, most of them nobody
 * here has ever heard of; walking those would be reading somebody else's
 * estate and producing findings for customers this installation does not
 * have. So the list comes from `domains` and the adapter is asked about each
 * — and a zone it does not hold answers null, which is an ordinary answer
 * when an installation has three DNS providers rather than a failure.
 *
 * **A zone nobody could read produces no findings and clears none.** An
 * adapter that is down must not look like a zone that was suddenly fixed:
 * silence is not the same as "all clear", and clearing on a failed read is
 * the bug that makes a findings list untrustworthy.
 *
 * Daily. A zone changes when somebody changes it, and an SPF record that has
 * been wrong for a month will still be wrong in an hour — asking a provider's
 * API for four hundred zones every five minutes is a rate limit and a bill.
 */
final readonly class InspectZones implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private InspectZone $inspector,
        private RecordZoneFindings $findings,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                foreach ($this->registry->all($organizationId) as $registered) {
                    $summary = $this->inspectWith($summary, $registered);
                }
            }

            return $summary;
        });
    }

    private function inspectWith(RunSummary $summary, RegisteredAdapter $registered): RunSummary
    {
        $adapter = $registered->adapter();

        if (! $adapter instanceof DnsProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::DnsZoneRead)) {
            // Examined and skipped rather than passed over in silence: an
            // adapter an operator turned off is why nothing arrived.
            return $summary->examining()->skipping();
        }

        $source = $registered->descriptor->key;
        $changed = 0;
        $examined = 0;

        foreach (Domain::query()->withoutGlobalScope('organization')->cursor() as $domain) {
            try {
                $zone = $adapter->zone($domain->name);
            } catch (Throwable $exception) {
                // One zone failing never stops the rest, and a read that
                // failed must not clear anything — an adapter that is down
                // is not a zone that was suddenly fixed.
                $summary = $summary->examining()->failing(new RunItem(
                    ItemOutcome::Failed,
                    ResourceAdapter::class,
                    $registered->row->id,
                    $domain->name,
                    $this->redactor->redactString($exception->getMessage()),
                ));

                continue;
            }

            if (! $zone instanceof DnsZone) {
                // This source is not authoritative for it. Ordinary.
                continue;
            }

            $examined++;

            $outcome = $this->findings->handle($domain, $source, $this->inspector->handle($zone));

            if ($outcome['raised'] > 0 || $outcome['cleared'] > 0) {
                $changed++;
            }
        }

        if ($examined === 0) {
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        return $changed === 0
            ? $summary->skipping()
            : $summary->changing(new RunItem(
                ItemOutcome::Changed,
                ResourceAdapter::class,
                $registered->row->id,
                $registered->descriptor->name,
                sprintf('%d zones read, %d changed', $examined, $changed),
            ));
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
