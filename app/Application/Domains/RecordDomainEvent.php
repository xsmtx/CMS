<?php

declare(strict_types=1);

namespace App\Application\Domains;

use App\Domain\Domains\DomainOperation;
use App\Domain\Provisioning\OperationOutcome;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\Models\DomainEvent;
use App\Support\Correlation\CorrelationContext;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What was attempted on a domain, and how it went.
 *
 * Append-only, and every message passes through the redactor first — a
 * registrar's error text quotes back the request, and a transfer request
 * carries an authorisation code.
 */
final readonly class RecordDomainEvent
{
    public function __construct(
        private SecretRedactor $redactor,
        private CorrelationContext $correlation,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Domain $domain,
        DomainOperation $operation,
        OperationOutcome $outcome,
        ?string $message = null,
        array $metadata = [],
        ?Model $actor = null,
    ): DomainEvent {
        /** @var array<string, mixed> $safe */
        $safe = $this->redactor->redact($metadata);

        return DomainEvent::query()->create([
            'organization_id' => $domain->organization_id,
            'domain_id' => $domain->id,
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
            return null;
        }

        if (method_exists($actor, 'displayName')) {
            return (string) $actor->displayName();
        }

        return (string) $actor->getAttribute('email');
    }
}
