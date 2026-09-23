<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Domains\RunDomainOperation;
use App\Application\Provisioning\RunServiceOperation;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Domains\DomainStatus;
use App\Domain\Domains\RegistrarResult;
use App\Domain\Provisioning\ProvisioningResult;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Asks providers what they think is true.
 *
 * Every phase since 6 has carried the same risk in its result document:
 * nothing reconciles this platform against the provider. A service
 * suspended directly in cPanel, a domain transferred away at the registrar,
 * an account deleted by hand — all of them leave a row here saying `active`
 * until somebody looks.
 *
 * This is the sweep that looks. It is deliberately slow and bounded: a
 * batch per run, oldest `synced_at` first, so an installation with ten
 * thousand services works through them over a day instead of asking a
 * control panel ten thousand questions in a minute and being rate-limited
 * out of its own account.
 *
 * What it does **not** do is apply a difference silently. The adapters
 * record what they found; a sync that would terminate a service because a
 * provider had a bad minute is worse than a stale row. That decision
 * belongs to an operator looking at the drift, which is the operations
 * centre's job rather than this task's.
 */
final readonly class SyncWithProviders implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private RunServiceOperation $services,
        private RunDomainOperation $domains,
    ) {}

    public function handle(): RunSummary
    {
        $summary = new RunSummary;

        foreach ($this->staleServices() as $service) {
            $summary = $summary->examining();

            try {
                $this->organizations->withoutBoundary(fn (): ProvisioningResult => $this->services->sync($service));

                $summary = $summary->changing(new RunItem(
                    ItemOutcome::Changed,
                    Service::class,
                    $service->id,
                    $service->name,
                ));
            } catch (Throwable $exception) {
                $summary = $summary->failing(new RunItem(
                    ItemOutcome::Failed,
                    Service::class,
                    $service->id,
                    $service->name,
                    $exception->getMessage(),
                ));
            }
        }

        foreach ($this->staleDomains() as $domain) {
            $summary = $summary->examining();

            try {
                $this->organizations->withoutBoundary(fn (): RegistrarResult => $this->domains->sync($domain));

                $summary = $summary->changing(new RunItem(
                    ItemOutcome::Changed,
                    Domain::class,
                    $domain->id,
                    $domain->name,
                ));
            } catch (Throwable $exception) {
                $summary = $summary->failing(new RunItem(
                    ItemOutcome::Failed,
                    Domain::class,
                    $domain->id,
                    $domain->name,
                    $exception->getMessage(),
                ));
            }
        }

        return $summary;
    }

    /**
     * @return list<Service>
     */
    private function staleServices(): array
    {
        $before = CarbonImmutable::now()->subHours($this->staleAfterHours());
        $batch = $this->batchSize();

        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(Service::query()
                ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
                ->whereNotNull('external_id')
                ->where(function ($query) use ($before): void {
                    $query->whereNull('synced_at')->orWhere('synced_at', '<', $before);
                })
                // Oldest first, so nothing starves behind a busy account.
                ->orderByRaw('synced_at IS NULL DESC, synced_at ASC')
                ->limit($batch)
                ->get()
                ->all()),
        );
    }

    /**
     * @return list<Domain>
     */
    private function staleDomains(): array
    {
        $before = CarbonImmutable::now()->subHours($this->staleAfterHours());
        $batch = $this->batchSize();

        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(Domain::query()
                ->where('status', DomainStatus::Active->value)
                ->whereNotNull('registrar')
                ->where(function ($query) use ($before): void {
                    $query->whereNull('synced_at')->orWhere('synced_at', '<', $before);
                })
                ->orderByRaw('synced_at IS NULL DESC, synced_at ASC')
                ->limit($batch)
                ->get()
                ->all()),
        );
    }

    private function staleAfterHours(): int
    {
        return (int) config('platform.automation.sync_after_hours', 24);
    }

    private function batchSize(): int
    {
        return (int) config('platform.automation.sync_batch', 50);
    }
}
