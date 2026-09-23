<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * One check's answer.
 *
 * `detail` is a sentence for a human and `measurements` are numbers for the
 * screen. **Neither may contain a configuration value.** A health page that
 * prints a DSN to prove the database is reachable has published a password,
 * and the temptation to do exactly that is why this is written here rather
 * than left to each check's author.
 */
final readonly class HealthReport
{
    /**
     * @param  array<string, string|int>  $measurements
     */
    public function __construct(
        public string $key,
        public HealthState $state,
        public ?string $detail = null,
        public array $measurements = [],
    ) {}

    /**
     * @param  array<string, string|int>  $measurements
     */
    public static function ok(string $key, array $measurements = []): self
    {
        return new self($key, HealthState::Ok, null, $measurements);
    }

    /**
     * @param  array<string, string|int>  $measurements
     */
    public static function degraded(string $key, string $detail, array $measurements = []): self
    {
        return new self($key, HealthState::Degraded, $detail, $measurements);
    }

    /**
     * @param  array<string, string|int>  $measurements
     */
    public static function failing(string $key, string $detail, array $measurements = []): self
    {
        return new self($key, HealthState::Failing, $detail, $measurements);
    }
}
