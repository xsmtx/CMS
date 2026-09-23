<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Automation\Listeners\AdvanceRenewalDates;
use App\Application\Health\HealthChecks;
use App\Domain\Billing\Events\PaymentReceived;
use App\Infrastructure\Health\Checks\CacheCheck;
use App\Infrastructure\Health\Checks\DatabaseCheck;
use App\Infrastructure\Health\Checks\FailedJobsCheck;
use App\Infrastructure\Health\Checks\MailCheck;
use App\Infrastructure\Health\Checks\ProviderCheck;
use App\Infrastructure\Health\Checks\QueueCheck;
use App\Infrastructure\Health\Checks\SchedulerCheck;
use App\Support\Logging\SecretRedactor;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The checks this installation runs, and the one listener automation adds.
 *
 * The check list is here, in order, rather than discovered from a
 * directory: the order they appear in is the order an operator reads them,
 * and "what does this page tell me" should be answerable by reading a file.
 */
final class AutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HealthChecks::class, fn (): HealthChecks => new HealthChecks(
            [
                new DatabaseCheck,
                new CacheCheck,
                new QueueCheck,
                new FailedJobsCheck,
                new SchedulerCheck,
                $this->app->make(MailCheck::class),
                $this->app->make(ProviderCheck::class),
            ],
            $this->app->make(SecretRedactor::class),
        ));
    }

    public function boot(): void
    {
        // A renewal invoice that was paid is what moves the service's date.
        // Not the invoice being raised, and not the scheduler: money is the
        // event that means the next term was bought.
        Event::listen(PaymentReceived::class, AdvanceRenewalDates::class);
    }
}
