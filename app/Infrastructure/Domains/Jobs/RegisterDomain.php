<?php

declare(strict_types=1);

namespace App\Infrastructure\Domains\Jobs;

use App\Application\Domains\RecordDomainEvent;
use App\Application\Domains\RunDomainOperation;
use App\Application\Domains\TransitionDomain;
use App\Domain\Domains\DomainOperation;
use App\Domain\Domains\DomainStatus;
use App\Domain\Provisioning\OperationOutcome;
use App\Infrastructure\Domains\Models\Domain;
use App\Support\Correlation\CorrelationContext;
use App\Support\Correlation\CorrelationId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Registers one name.
 *
 * The same shape as `ProvisionService`, and for the same reasons: unique on
 * the subject so two paid-order events cannot register twice, bounded tries
 * with backoff, the correlation id carried from the request that started
 * it, and a `failed()` hook so a domain never sits in `registering`
 * forever.
 *
 * The backoff is longer than provisioning's. A control panel that is
 * rebooting comes back in a minute; a registry under load, or a TLD whose
 * registry runs batch windows, does not.
 */
final class RegisterDomain implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $domainId,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue('domains');
    }

    public function uniqueId(): string
    {
        return $this->domainId;
    }

    public function tries(): int
    {
        return (int) config('platform.domains.job_tries', 3);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(RunDomainOperation $operations, CorrelationContext $correlation): void
    {
        $carried = CorrelationId::tryFrom($this->correlationId);

        if ($carried instanceof CorrelationId) {
            $correlation->set($carried);
        }

        $domain = $this->domain();

        if ($domain === null) {
            return;
        }

        if (! $domain->status->canRegister()) {
            // Already registered, or cancelled while this sat in the queue.
            // Registering a second time costs real money at a registry.
            return;
        }

        if ($domain->registrar === null || $domain->registrar === '') {
            // No registrar: an operator registers it by hand. Leaving it
            // `pending` is correct — it is waiting for a person.
            return;
        }

        $operations->register($domain);
    }

    public function failed(?Throwable $exception): void
    {
        $domain = $this->domain();

        if ($domain === null) {
            return;
        }

        $message = $exception?->getMessage() ?? (string) __('domains.errors.job_failed');

        app(RecordDomainEvent::class)->handle(
            $domain,
            DomainOperation::Register,
            OperationOutcome::Failed,
            $message,
        );

        if ($domain->status->canTransitionTo(DomainStatus::Failed)) {
            app(TransitionDomain::class)->handle($domain, DomainStatus::Failed, null, $message);
        }
    }

    private function domain(): ?Domain
    {
        return Domain::query()
            ->withoutGlobalScope('organization')
            ->with(['customer.primaryContact', 'tld'])
            ->find($this->domainId);
    }
}
