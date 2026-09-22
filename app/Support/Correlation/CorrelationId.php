<?php

declare(strict_types=1);

namespace App\Support\Correlation;

use Illuminate\Support\Str;
use Stringable;

/**
 * A correlation identifier: one opaque, log-safe token that ties a request,
 * the jobs it dispatches, the provider calls they make and the audit records
 * they write into a single traceable unit of work.
 */
final readonly class CorrelationId implements Stringable
{
    private const string ULID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i';

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public static function generate(): self
    {
        return new self((string) Str::ulid());
    }

    /**
     * Accept an externally supplied identifier only when it is a well-formed
     * ULID or UUID. Anything else is discarded: an attacker must not be able
     * to poison log aggregation with arbitrary strings.
     */
    public static function tryFrom(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if (preg_match(self::ULID_PATTERN, $value) === 1) {
            return new self(strtoupper($value));
        }

        if (preg_match(self::UUID_PATTERN, $value) === 1) {
            return new self(strtolower($value));
        }

        return null;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
