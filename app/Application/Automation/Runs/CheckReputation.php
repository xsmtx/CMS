<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Security\RecordListings;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\ReputationProvider;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Asks every reputation source about this installation's own addresses (§13).
 *
 * **The address list comes from `ip_addresses`, which is the seller's own
 * plan.** A row exists there because somebody did something with the address
 * (Phase C §5), so the list is exactly what this installation has put its
 * name to — and asking a blocklist about anything wider would be checking up
 * on other people's networks.
 *
 * **A source that could not be read clears nothing.** An adapter that is down
 * must not look like a blocklist that suddenly lifted every listing; silence
 * is not the same as "all clear", and that is the one bug that would make
 * this screen worth ignoring.
 *
 * **An organization with no addresses is skipped rather than asked.** Handing
 * an adapter an empty list and recording its empty answer would clear every
 * open listing on the first run after somebody deleted a prefix.
 *
 * Daily. A blocklist takes hours to list and days to delist, and asking one
 * every five minutes is a rate limit rather than fresher data.
 */
final readonly class CheckReputation implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private RecordListings $listings,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                $addresses = $this->addressesOf($organizationId);

                if ($addresses === []) {
                    continue;
                }

                foreach ($this->registry->all($organizationId) as $registered) {
                    $summary = $this->askOne($summary, $organizationId, $addresses, $registered);
                }
            }

            return $summary;
        });
    }

    /**
     * @param  list<string>  $addresses
     */
    private function askOne(
        RunSummary $summary,
        string $organizationId,
        array $addresses,
        RegisteredAdapter $registered,
    ): RunSummary {
        $adapter = $registered->adapter();

        if (! $adapter instanceof ReputationProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::ReputationRead)) {
            // Examined and skipped rather than passed over in silence: an
            // adapter an operator turned off is why nothing arrived.
            return $summary->examining()->skipping();
        }

        try {
            $found = $adapter->listings($addresses);
        } catch (Throwable $exception) {
            return $summary->examining()->failing(new RunItem(
                ItemOutcome::Failed,
                ResourceAdapter::class,
                $registered->row->id,
                $registered->descriptor->name,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        $outcome = $this->listings->handle($organizationId, $registered->descriptor->key, $found);
        $summary = $summary->examining();

        if ($outcome['raised'] === 0 && $outcome['cleared'] === 0) {
            return $summary->skipping();
        }

        return $summary->changing(new RunItem(
            ItemOutcome::Changed,
            ResourceAdapter::class,
            $registered->row->id,
            $registered->descriptor->name,
            sprintf('%d listed, %d lifted', $outcome['raised'], $outcome['cleared']),
        ));
    }

    /**
     * @return list<string>
     */
    private function addressesOf(string $organizationId): array
    {
        return array_values(IpAddressRecord::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->orderBy('address_bytes')
            ->pluck('address')
            ->all());
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
