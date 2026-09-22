<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Support\Audit\Contracts\AuditRecorder;
use PHPUnit\Framework\Assert;

/**
 * In-memory recorder for tests and for module authors verifying that their
 * feature writes the audit records the Definition of Done requires.
 */
final class FakeAuditRecorder implements AuditRecorder
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    public function action(string $action): PendingAudit
    {
        return new PendingAudit($this, $action);
    }

    public function record(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<AuditEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @param  (callable(AuditEntry): bool)|null  $filter
     */
    public function assertRecorded(string $action, ?callable $filter = null): void
    {
        $matches = array_filter(
            $this->entries,
            static fn (AuditEntry $entry): bool => $entry->action === $action
                && ($filter === null || $filter($entry)),
        );

        Assert::assertNotSame(
            [],
            $matches,
            "No audit entry recorded for action [{$action}].",
        );
    }

    public function assertNothingRecorded(): void
    {
        Assert::assertSame([], $this->entries, 'Expected no audit entries to be recorded.');
    }
}
