<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Carbon\CarbonImmutable;

/**
 * One immutable audit fact, ready to be persisted.
 *
 * Actor and target identity are denormalised on purpose: an audit row must
 * stay readable after the subject it refers to has been renamed, anonymised
 * or deleted.
 */
final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $action,
        public ?string $organizationId = null,
        public ?string $actorType = null,
        public ?string $actorId = null,
        public ?string $actorLabel = null,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public ?string $targetLabel = null,
        public array $changes = [],
        public array $metadata = [],
        public ?string $reason = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $correlationId = null,
        public ?CarbonImmutable $occurredAt = null,
    ) {}
}
