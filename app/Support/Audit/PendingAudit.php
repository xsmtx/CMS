<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Support\Audit\Contracts\AuditLabel;
use App\Support\Audit\Contracts\AuditRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fluent builder for an audit record.
 *
 * Audit::action('role.assigned')
 *     ->by($staffUser)
 *     ->on($role)
 *     ->changed($before, $after)
 *     ->because('Onboarding ticket #1421')
 *     ->write();
 */
final class PendingAudit
{
    private ?string $actorType = null;

    private ?string $actorId = null;

    private ?string $actorLabel = null;

    private ?string $targetType = null;

    private ?string $targetId = null;

    private ?string $targetLabel = null;

    /** @var array<string, mixed> */
    private array $changes = [];

    /** @var array<string, mixed> */
    private array $metadata = [];

    private ?string $reason = null;

    private ?string $organizationId = null;

    public function __construct(
        private readonly AuditRecorder $recorder,
        private readonly string $action,
    ) {}

    /**
     * The actor may be absent: automation, scheduled tasks and webhook
     * handlers legitimately act without a user.
     */
    public function by(?Model $actor, ?string $label = null): self
    {
        $this->actorType = $actor?->getMorphClass();
        $this->actorId = $actor === null ? null : (string) $actor->getKey();
        $this->actorLabel = $label ?? $this->labelFor($actor);

        return $this;
    }

    public function bySystem(string $label): self
    {
        $this->actorType = null;
        $this->actorId = null;
        $this->actorLabel = $label;

        return $this;
    }

    public function on(?Model $target, ?string $label = null): self
    {
        $this->targetType = $target?->getMorphClass();
        $this->targetId = $target === null ? null : (string) $target->getKey();
        $this->targetLabel = $label ?? $this->labelFor($target);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function changed(array $before, array $after): self
    {
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        $diff = [];

        foreach ($keys as $key) {
            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;

            if ($from === $to) {
                continue;
            }

            $diff[$key] = ['from' => $from, 'to' => $to];
        }

        $this->changes = $diff;

        return $this;
    }

    /**
     * Derive the diff from a model that has just been saved.
     */
    public function changedFrom(Model $model): self
    {
        $after = $model->getChanges();

        /** @var array<string, mixed> $original */
        $original = $model->getOriginal();

        $before = array_intersect_key($original, $after);

        return $this->changed($before, $after);
    }

    public function because(?string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = [...$this->metadata, ...$metadata];

        return $this;
    }

    /**
     * Attribute the record to a specific organization.
     *
     * Normally the boundary of the current unit of work is used; this is for
     * the rare case where an action is performed on behalf of another
     * organization and must be filed under theirs.
     */
    public function forOrganization(?string $organizationId): self
    {
        $this->organizationId = $organizationId;

        return $this;
    }

    public function write(): void
    {
        $this->recorder->record(new AuditEntry(
            action: $this->action,
            organizationId: $this->organizationId,
            actorType: $this->actorType,
            actorId: $this->actorId,
            actorLabel: $this->actorLabel,
            targetType: $this->targetType,
            targetId: $this->targetId,
            targetLabel: $this->targetLabel,
            changes: $this->changes,
            metadata: $this->metadata,
            reason: $this->reason,
        ));
    }

    private function labelFor(?Model $model): ?string
    {
        if ($model === null) {
            return null;
        }

        if ($model instanceof AuditLabel) {
            return $model->auditLabel();
        }

        return class_basename($model).'#'.$model->getKey();
    }
}
