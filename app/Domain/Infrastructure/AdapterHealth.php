<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Domain\Health\HealthState;
use Carbon\CarbonImmutable;

/**
 * Whether an adapter can currently do its job, in the adapter's own words.
 *
 * Reuses `HealthState` rather than inventing a fourth vocabulary for the same
 * three answers — the health screen, the provisioning server list and this all
 * mean the same thing by `degraded`, and that is worth more than a bespoke enum.
 *
 * Two fields exist for reasons that are not obvious:
 *
 * `remoteVersion` is what the adapter *saw*, not what it supports. An operator
 * debugging why a FortiGate adapter returns no policies needs the firmware
 * version on the screen next to the word "degraded", because the answer is
 * usually that somebody upgraded the device.
 *
 * `supported` being false is deliberately a **degraded** adapter rather than a
 * failing one. An unsupported remote version means some calls will work and some
 * will not; failing the whole adapter would hide the readings that still arrive,
 * and this platform has a rule about that already — `degraded` is what a queue
 * with a thousand waiting jobs is.
 *
 * **It never carries configuration.** Not a host, not a DSN, not a token
 * prefix. That rule was written for the health check in Phase 9 and a test
 * asserts it here too: this object is rendered on a screen and logged.
 */
final readonly class AdapterHealth
{
    public function __construct(
        public HealthState $state,
        /** One sentence, for an operator. Never a stack trace, never a URL. */
        public ?string $message = null,
        public ?string $remoteVersion = null,
        public bool $supported = true,
        public ?CarbonImmutable $checkedAt = null,
    ) {}

    public static function ok(?string $remoteVersion = null): self
    {
        return new self(HealthState::Ok, remoteVersion: $remoteVersion, checkedAt: CarbonImmutable::now());
    }

    public static function degraded(string $message, ?string $remoteVersion = null): self
    {
        return new self(HealthState::Degraded, $message, $remoteVersion, checkedAt: CarbonImmutable::now());
    }

    public static function failing(string $message): self
    {
        return new self(HealthState::Failing, $message, checkedAt: CarbonImmutable::now());
    }

    /**
     * The remote is a version this adapter was not written against.
     *
     * A named constructor rather than an argument, because it is the case an
     * adapter author will otherwise report as `failing` — and then an operator
     * sees a dead integration instead of a working one with a caveat.
     */
    public static function unsupportedVersion(string $remoteVersion, string $message): self
    {
        return new self(
            HealthState::Degraded,
            $message,
            $remoteVersion,
            supported: false,
            checkedAt: CarbonImmutable::now(),
        );
    }
}
