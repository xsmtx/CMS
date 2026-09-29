<?php

declare(strict_types=1);

namespace App\Domain\Api;

use Carbon\CarbonImmutable;

/**
 * What a client is handed when a device session opens or rotates.
 *
 * The two plain-text values exist in this object and nowhere else: both are
 * stored hashed, so this is the only moment either can be read. Nothing here
 * is ever written to a log or an audit row — the audit records that a session
 * was issued and to which device, which is the question somebody asks
 * afterwards, and never what was issued.
 */
final readonly class DeviceSession
{
    public function __construct(
        public string $deviceId,
        public string $accessToken,
        public CarbonImmutable $accessExpiresAt,
        public string $refreshToken,
        public CarbonImmutable $refreshExpiresAt,
    ) {}
}
