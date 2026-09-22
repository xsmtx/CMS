<?php

declare(strict_types=1);

namespace App\Support\Logging;

use JsonSerializable;

/**
 * Removes credentials and cardholder data from arbitrary structures before
 * they reach a log line, an audit row or an error response.
 *
 * Matching is done on key fragments rather than exact names so that
 * `password`, `password_confirmation`, `db_password` and `oldPassword` are
 * all caught without maintaining an exhaustive list.
 */
final readonly class SecretRedactor
{
    /**
     * 13-19 digits, optionally grouped by spaces or hyphens: the shape of a
     * payment card number. Caught as a safety net; card data must never be
     * passed to the platform in the first place.
     */
    private const string CARD_LIKE = '/\b(?:\d[ -]?){12,18}\d\b/';

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        private array $keys,
        private string $placeholder = '[redacted]',
        private int $maxDepth = 16,
        private bool $redactCardLikeValues = true,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        return $this->walk($data, 0);
    }

    public function redactString(string $value): string
    {
        if (! $this->redactCardLikeValues) {
            return $value;
        }

        $result = preg_replace(self::CARD_LIKE, $this->placeholder, $value);

        return $result ?? $value;
    }

    public function isSecretKey(string $key): bool
    {
        $needle = strtolower($key);

        return array_any($this->keys, fn (string $fragment): bool => str_contains($needle, strtolower($fragment)));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function walk(array $data, int $depth): array
    {
        if ($depth >= $this->maxDepth) {
            return [$this->placeholder];
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                $result[$key] = $this->placeholder;

                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => $this->walk($value, $depth + 1),
                is_string($value) => $this->redactString($value),
                $value instanceof JsonSerializable => $this->walk(
                    (array) $value->jsonSerialize(),
                    $depth + 1,
                ),
                default => $value,
            };
        }

        return $result;
    }
}
