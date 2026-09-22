<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * A raw inbound webhook, before anything is believed about it.
 *
 * The body is kept as a string because signatures are computed over exact
 * bytes: decoding and re-encoding JSON changes them, and a verification
 * that passes on re-encoded input is not a verification.
 */
final readonly class WebhookRequest
{
    /**
     * @param  array<string, string>  $headers  lower-cased names
     */
    public function __construct(
        public string $body,
        public array $headers = [],
        public ?string $ipAddress = null,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
