<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Stringable;

/**
 * "Which versions does this work with", kept deliberately small.
 *
 * `*` for anything, `>=x.y` for a floor, `<x.y` for a ceiling, `x.y - a.b`
 * for a span, and an exact version for an exact version. That is the whole
 * grammar.
 *
 * A full semver range parser is a dependency and a class of bug, and an
 * ecosystem that needs `^1.2 || ~2.0` does not exist here yet. When it does,
 * this is the one place that changes — which is the reason it is a value
 * object rather than a static helper copied into each manifest.
 *
 * An unparseable range is **not** treated as "anything". A package whose
 * compatibility nobody can read is a package nobody should install, and
 * defaulting to permissive is how that decision gets made by accident.
 */
final readonly class VersionRange implements Stringable
{
    public function __construct(public string $range = '*') {}

    public function __toString(): string
    {
        return $this->range;
    }

    public static function any(): self
    {
        return new self('*');
    }

    public function allows(string $version): bool
    {
        $range = trim($this->range);

        if ($range === '' || $range === '*') {
            return true;
        }

        if (str_contains($range, ' - ')) {
            [$floor, $ceiling] = array_map(trim(...), explode(' - ', $range, 2));

            return version_compare($version, $floor, '>=')
                && version_compare($version, $ceiling, '<=');
        }

        foreach (['>=', '<=', '>', '<'] as $operator) {
            if (str_starts_with($range, $operator)) {
                return version_compare($version, trim(substr($range, strlen($operator))), $operator);
            }
        }

        return version_compare($version, $range, '==');
    }

    /**
     * Whether the range is one this class understands at all.
     *
     * Asked separately from `allows()` so that a refusal can say "nobody can
     * read this" rather than "your platform is too old", which sends an
     * operator looking in the wrong place.
     */
    public function isUnderstood(): bool
    {
        $range = trim($this->range);

        if ($range === '' || $range === '*') {
            return true;
        }

        if (str_contains($range, ' - ')) {
            [$floor, $ceiling] = array_map(trim(...), explode(' - ', $range, 2));

            return $this->isVersion($floor) && $this->isVersion($ceiling);
        }

        foreach (['>=', '<=', '>', '<'] as $operator) {
            if (str_starts_with($range, $operator)) {
                return $this->isVersion(trim(substr($range, strlen($operator))));
            }
        }

        return $this->isVersion($range);
    }

    private function isVersion(string $value): bool
    {
        return $value !== '' && preg_match('/^\d+(\.\d+)*$/', $value) === 1;
    }
}
