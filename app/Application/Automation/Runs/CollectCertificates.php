<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Security\RecordCertificates;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\CertificateProvider;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Asks every certificate source what it currently has deployed (§8).
 *
 * **The whole inventory, not a window.** Unlike the DDoS collector, which
 * asks "what has happened since", this asks "what is there now" — because a
 * certificate that has *stopped* being deployed is the interesting answer. A
 * source that no longer names one has replaced or removed it, and core
 * retires the row rather than deleting it.
 *
 * **Hourly is often enough.** A certificate's life is measured in weeks and
 * the thing an operator wants to hear about — a renewal that silently failed
 * — is visible for days before it matters. Asking a control panel every five
 * minutes for a list that changes twice a month is a denial of service
 * against your own operator, which is the reason Phase A gave for not
 * polling adapters hard.
 *
 * One source failing never stops the others, and the failure is recorded
 * against the adapter row through the redactor — a provider's exception
 * message is a likely place for a credential to reach a column that is then
 * read on a screen.
 */
final readonly class CollectCertificates implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private RecordCertificates $certificates,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                foreach ($this->registry->all($organizationId) as $registered) {
                    $summary = $this->collectFrom($summary, $organizationId, $registered);
                }
            }

            return $summary;
        });
    }

    private function collectFrom(
        RunSummary $summary,
        string $organizationId,
        RegisteredAdapter $registered,
    ): RunSummary {
        $adapter = $registered->adapter();

        if (! $adapter instanceof CertificateProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::CertificateRead)) {
            // Examined and skipped rather than passed over in silence: an
            // adapter an operator turned off is why nothing arrived, and the
            // run record is where that question gets asked.
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        try {
            $found = $adapter->certificates();
        } catch (Throwable $exception) {
            return $summary->failing(new RunItem(
                ItemOutcome::Failed,
                ResourceAdapter::class,
                $registered->row->id,
                $registered->descriptor->name,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        $outcome = $this->certificates->handle($organizationId, $registered->descriptor->key, $found);

        // Nothing found **and** nothing retired is a source that has nothing
        // to say, which is different from one that failed. A run reporting a
        // change every hour for an inventory that has not moved would make
        // the hour something actually changed impossible to see.
        if ($outcome['recorded'] === 0 && $outcome['retired'] === 0) {
            return $summary->skipping();
        }

        return $summary->changing(new RunItem(
            ItemOutcome::Changed,
            ResourceAdapter::class,
            $registered->row->id,
            $registered->descriptor->name,
            sprintf('%d deployed, %d retired', $outcome['recorded'], $outcome['retired']),
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
