<?php

declare(strict_types=1);

namespace App\Domain\Domains;

use App\Domain\Domains\Exceptions\InvalidDomainName;

/**
 * A domain name, validated and split.
 *
 * Parsing lives here rather than in a form request because the order line,
 * the provisioning call and the registrar adapter all need the same split,
 * and three implementations of "everything after the first dot" is three
 * chances to disagree about `co.uk`.
 */
final readonly class DomainName
{
    private function __construct(
        public string $value,
        public string $sld,
        public string $tld,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public static function parse(string $input): self
    {
        $normalised = strtolower(trim($input));
        $normalised = preg_replace('#^https?://#', '', $normalised) ?? $normalised;
        $normalised = explode('/', $normalised)[0];
        $normalised = trim($normalised, '.');

        if ($normalised === '' || ! str_contains($normalised, '.')) {
            throw InvalidDomainName::for($input);
        }

        // Labels are letters, digits and hyphens, not starting or ending
        // with a hyphen. Punycode passes as-is; a Unicode name is converted
        // by the caller before it reaches here.
        if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $normalised) !== 1) {
            throw InvalidDomainName::for($input);
        }

        $labels = explode('.', $normalised);
        $sld = array_shift($labels);

        return new self($normalised, $sld, implode('.', $labels));
    }
}
