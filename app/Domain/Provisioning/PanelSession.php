<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * A one-time way into a control panel, issued by the panel itself.
 *
 * The point of this object is what it is **not**: it is not a password. The
 * platform holds a server's API credential in order to create accounts; it
 * uses that credential to ask the panel for a short-lived session URL, and
 * hands the operator the URL. The credential never leaves the server this
 * runs on, and never reaches a browser.
 *
 * That is the difference between this and "show me the root password",
 * which this platform does not do and will not: a session expires, is
 * bound to one panel, and is attributable to the operator who asked for it.
 *
 * The URL itself is a bearer credential for its lifetime, so it is never
 * stored, never logged and never rendered into a page — it is handed
 * straight to a redirect and forgotten.
 */
final readonly class PanelSession
{
    public function __construct(
        #[SensitiveParameter]
        public string $url,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $panel = null,
    ) {}

    /**
     * Never print the URL, whatever prints this object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'panel' => $this->panel,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'url' => '[redacted]',
        ];
    }
}
