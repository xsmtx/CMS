<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Infrastructure\Audit\Models\AuditLog;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\Contracts\AuditRecorder;
use App\Support\Audit\PendingAudit;
use App\Support\Correlation\CorrelationContext;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Writes audit records to the primary database.
 *
 * Writes are synchronous: an audit record that is queued can be lost when the
 * queue is unavailable, and losing the record of a suspension or a refund is
 * worse than a few milliseconds of request latency.
 */
final readonly class DatabaseAuditRecorder implements AuditRecorder
{
    public function __construct(
        private CorrelationContext $correlation,
        private OrganizationContext $organizations,
        private SecretRedactor $redactor,
        private Request $request,
    ) {}

    public function action(string $action): PendingAudit
    {
        return new PendingAudit($this, $action);
    }

    public function record(AuditEntry $entry): void
    {
        AuditLog::query()->create([
            'organization_id' => $entry->organizationId ?? $this->organizations->id(),
            'action' => $entry->action,
            'actor_type' => $entry->actorType,
            'actor_id' => $entry->actorId,
            'actor_label' => $this->truncate($entry->actorLabel, 255),
            'target_type' => $entry->targetType,
            'target_id' => $entry->targetId,
            'target_label' => $this->truncate($entry->targetLabel, 255),
            'changes' => $entry->changes === [] ? null : $this->redactor->redact($entry->changes),
            'metadata' => $entry->metadata === [] ? null : $this->redactor->redact($entry->metadata),
            'reason' => $entry->reason,
            'ip_address' => $entry->ipAddress ?? $this->request->ip(),
            'user_agent' => $this->truncate($entry->userAgent ?? $this->request->userAgent(), 512),
            'correlation_id' => $entry->correlationId ?? $this->correlation->idOrGenerate(),
            'occurred_at' => $entry->occurredAt ?? CarbonImmutable::now(),
        ]);
    }

    private function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
