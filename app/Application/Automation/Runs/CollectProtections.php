<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RecordProtections;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\BackupProvider;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Asks every backup source what it is currently protecting (§12).
 *
 * **A read that failed retires nothing.** The exception is caught here and
 * the source is recorded as failed without `RecordProtections` ever being
 * called — because an empty answer is taken literally, and a source that is
 * merely unreachable would otherwise retire the whole estate and make every
 * customer appear unprotected at three in the morning. That is the single
 * worst thing this family could do, and it is the reason the contract says a
 * failed read must throw rather than return an empty list.
 *
 * Hourly. A nightly job finishes at some hour of the night and an operator
 * wants to see it that morning; asking a backup vendor's API every five
 * minutes is a rate limit rather than fresher data.
 */
final readonly class CollectProtections implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private RecordProtections $protections,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                foreach ($this->registry->all($organizationId) as $registered) {
                    $summary = $this->askOne($summary, $organizationId, $registered);
                }
            }

            return $summary;
        });
    }

    private function askOne(RunSummary $summary, string $organizationId, RegisteredAdapter $registered): RunSummary
    {
        $adapter = $registered->adapter();

        if (! $adapter instanceof BackupProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::BackupStatusRead)) {
            // Examined and skipped rather than passed over in silence: an
            // adapter an operator turned off is why nothing arrived.
            return $summary->examining()->skipping();
        }

        try {
            $found = $adapter->protectedResources();
        } catch (Throwable $exception) {
            return $summary->examining()->failing(new RunItem(
                ItemOutcome::Failed,
                ResourceAdapter::class,
                $registered->row->id,
                $registered->descriptor->name,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        $outcome = $this->protections->handle($organizationId, $registered->descriptor->key, $found);

        return $summary->examining()->changing(new RunItem(
            ItemOutcome::Changed,
            ResourceAdapter::class,
            $registered->row->id,
            $registered->descriptor->name,
            sprintf(
                '%d protected, %d matched to a service, %d retired',
                $outcome['recorded'],
                $outcome['matched'],
                $outcome['retired'],
            ),
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
