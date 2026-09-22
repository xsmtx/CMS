<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Domain\Provisioning\OperationOutcome;
use App\Domain\Provisioning\ServiceOperation;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceEvent;
use App\Support\Correlation\CorrelationContext;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What was attempted, and how it went.
 *
 * Append-only. Every message passes through the redactor first, because a
 * provider's error text has a habit of quoting back the request that caused
 * it — including the password that was in it.
 */
final readonly class RecordServiceEvent
{
    public function __construct(
        private SecretRedactor $redactor,
        private CorrelationContext $correlation,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Service $service,
        ServiceOperation $operation,
        OperationOutcome $outcome,
        ?string $message = null,
        array $metadata = [],
        ?Model $actor = null,
    ): ServiceEvent {
        /** @var array<string, mixed> $safe */
        $safe = $this->redactor->redact($metadata);

        return ServiceEvent::query()->create([
            'organization_id' => $service->organization_id,
            'service_id' => $service->id,
            'operation' => $operation->value,
            'outcome' => $outcome->value,
            'actor_label' => $this->label($actor),
            'message' => $message === null ? null : $this->redactor->redactString($message),
            'metadata' => $safe === [] ? null : $safe,
            'correlation_id' => $this->correlation->id(),
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    private function label(?Model $actor): ?string
    {
        if ($actor === null) {
            // Null means the platform did it, which is most of them: a
            // paid order provisions without anybody pressing anything.
            return null;
        }

        if (method_exists($actor, 'displayName')) {
            return (string) $actor->displayName();
        }

        return (string) $actor->getAttribute('email');
    }
}
