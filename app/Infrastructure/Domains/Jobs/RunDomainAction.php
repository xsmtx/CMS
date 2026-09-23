<?php

declare(strict_types=1);

namespace App\Infrastructure\Domains\Jobs;

use App\Application\Domains\RecordDomainEvent;
use App\Application\Domains\RunDomainOperation;
use App\Domain\Domains\DomainOperation;
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
 * Renew, sync, or change a setting at the registrar.
 *
 * One job for the operations that differ only in which method they call,
 * for the same reason `RunServiceAction` is one job: four near-identical
 * classes drift, and the retry policy gets fixed in one of them.
 *
 * Registering has its own job, with its own uniqueness and its own failure
 * state, because it is the one that spends money.
 */
final class RunDomainAction implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 1800;

    /**
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public readonly string $domainId,
        public readonly DomainOperation $operation,
        public readonly ?bool $flag = null,
        public readonly array $nameservers = [],
        public readonly int $years = 1,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue('domains');
    }

    public function uniqueId(): string
    {
        return $this->domainId.':'.$this->operation->value;
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

        match ($this->operation) {
            DomainOperation::Renew => $operations->renew($domain, $this->years),
            DomainOperation::SetNameservers => $operations->setNameservers($domain, $this->nameservers),
            DomainOperation::SetLock => $operations->setLock($domain, (bool) $this->flag),
            DomainOperation::SetAutoRenew => $operations->setAutoRenew($domain, (bool) $this->flag),
            DomainOperation::Sync => $operations->sync($domain),
            // Registering and transferring have their own jobs. Anything
            // else is a no-op here rather than a surprise.
            default => null,
        };
    }

    public function failed(?Throwable $exception): void
    {
        $domain = $this->domain();

        if ($domain === null) {
            return;
        }

        app(RecordDomainEvent::class)->handle(
            $domain,
            $this->operation,
            OperationOutcome::Failed,
            $exception?->getMessage() ?? (string) __('domains.errors.job_failed'),
        );
    }

    private function domain(): ?Domain
    {
        return Domain::query()
            ->withoutGlobalScope('organization')
            ->find($this->domainId);
    }
}
