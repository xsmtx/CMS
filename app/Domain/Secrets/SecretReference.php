<?php

declare(strict_types=1);

namespace App\Domain\Secrets;

use App\Domain\Secrets\Exceptions\InvalidSecretReference;

/**
 * Where a secret is, said in a way that is safe to store and to print.
 *
 * Three parts: the **area** that owns it (`monitoring`, `network`, `backup`),
 * the **kind** of credential it is (`token`, `password`, `api-key`), and the
 * **owner** it belongs to — an adapter row's id, a server's id, whatever the
 * thing is that has one of its own.
 *
 * A reference is the value objects that travel: rows hold it, screens print
 * it, audit records name it. It is deliberately readable, because the
 * alternative — an opaque id — is a support call that cannot be answered
 * without a database.
 *
 * Every part is constrained to `[a-z0-9-]` and a length, for the reason the
 * marketplace reduces a provider's name the same way: a reference is
 * frequently assembled from something somebody else filled in, and a
 * reference with a slash in it is a key that collides with another one.
 */
final readonly class SecretReference
{
    public function __construct(
        public string $area,
        public string $kind,
        public string $owner,
    ) {
        $this->assertSafe($area, 'area');
        $this->assertSafe($kind, 'kind');
        $this->assertSafe($owner, 'owner');
    }

    public function __toString(): string
    {
        return $this->key();
    }

    /**
     * The stored form: `area/kind/owner`.
     */
    public function key(): string
    {
        return $this->area.'/'.$this->kind.'/'.$this->owner;
    }

    public static function parse(string $key): self
    {
        $parts = explode('/', $key);

        if (count($parts) !== 3) {
            throw InvalidSecretReference::malformed($key);
        }

        return new self($parts[0], $parts[1], $parts[2]);
    }

    private function assertSafe(string $part, string $name): void
    {
        if ($part === '' || mb_strlen($part) > 64) {
            throw InvalidSecretReference::badPart($name, $part);
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $part) !== 1) {
            throw InvalidSecretReference::badPart($name, $part);
        }
    }
}
