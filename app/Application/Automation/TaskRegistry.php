<?php

declare(strict_types=1);

namespace App\Application\Automation;

use App\Application\Automation\Runs\CheckAdapterHealth;
use App\Application\Automation\Runs\CleanUpExpiredRecords;
use App\Application\Automation\Runs\CollectTelemetry;
use App\Application\Automation\Runs\GenerateRenewalInvoices;
use App\Application\Automation\Runs\MarkInvoicesOverdue;
use App\Application\Automation\Runs\NotifyExpiringDomains;
use App\Application\Automation\Runs\ProjectCoreResources;
use App\Application\Automation\Runs\RetryFailedOperations;
use App\Application\Automation\Runs\RetryWebhookDeliveries;
use App\Application\Automation\Runs\RunDunningSequence;
use App\Application\Automation\Runs\SendLicenceHeartbeat;
use App\Application\Automation\Runs\SyncWithProviders;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\Contracts\AutomationRun;
use Illuminate\Contracts\Container\Container;

/**
 * Which class runs which task.
 *
 * One map, resolved from the container, so the console command, the
 * scheduler and the admin "run now" button all reach the same object. A
 * `match` inside the command would have been shorter and would have meant
 * the screen could offer a task the command could not run.
 */
final readonly class TaskRegistry
{
    public function __construct(private Container $container) {}

    public function resolve(AutomationTask $task): AutomationRun
    {
        /** @var AutomationRun */
        return $this->container->make(match ($task) {
            AutomationTask::Renewals => GenerateRenewalInvoices::class,
            AutomationTask::Dunning => RunDunningSequence::class,
            AutomationTask::Overdue => MarkInvoicesOverdue::class,
            AutomationTask::DomainExpiry => NotifyExpiringDomains::class,
            AutomationTask::Retries => RetryFailedOperations::class,
            AutomationTask::Sync => SyncWithProviders::class,
            AutomationTask::Webhooks => RetryWebhookDeliveries::class,
            AutomationTask::Cleanup => CleanUpExpiredRecords::class,
            AutomationTask::Resources => ProjectCoreResources::class,
            AutomationTask::Telemetry => CollectTelemetry::class,
            AutomationTask::AdapterHealth => CheckAdapterHealth::class,
            AutomationTask::Licence => SendLicenceHeartbeat::class,
        });
    }
}
