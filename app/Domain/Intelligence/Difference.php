<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * One thing a comparison concluded, at one moment (§22).
 *
 * The recorder's currency, and the reason it is a value object rather than a
 * row: a comparison is not stored. What is stored is the **finding** that a
 * difference which is still true produced — and a difference that agrees
 * produces nothing at all, which is what makes the queue something an
 * operator can finish reading.
 *
 * `key` is what the finding de-duplicates on and `label` is what somebody
 * reads. They are different on purpose, the same way an `Observation`'s are:
 * a key is a ULID or a provider's own identifier and a label is
 * "acme-hosting on web-7", and a queue keyed on the label would split in two
 * the day somebody renamed a server.
 *
 * `expected` and `found` are **the words each side used**, never this
 * platform's vocabulary for them. "The panel said `suspended`" is a different
 * sentence from "we mapped it to suspended", and six weeks later the first
 * one is the only one that explains the finding.
 */
final readonly class Difference
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public ReconciliationClass $class,
        public string $resource,
        public string $label,
        /** The row here, where there is one. Null is what an orphan is. */
        public ?string $subjectId = null,
        public ?string $subjectType = null,
        /** The provider's own identifier, which makes an orphan addressable. */
        public ?string $remoteKey = null,
        /** Which attribute disagreed. Null where the whole thing is the finding. */
        public ?string $field = null,
        public ?string $expected = null,
        public ?string $found = null,
        public array $detail = [],
    ) {}

    /**
     * What this de-duplicates on, within one source and organization.
     *
     * The subject and the remote key both, because the three classes use
     * them differently: `missing` has a subject and no remote key, `orphan`
     * has a remote key and no subject, and `drift` has both.
     */
    public function key(): string
    {
        return implode('|', [
            $this->resource,
            $this->subjectId ?? '',
            $this->remoteKey ?? '',
            $this->field ?? '',
        ]);
    }
}
