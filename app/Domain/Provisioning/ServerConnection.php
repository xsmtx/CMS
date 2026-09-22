<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

use SensitiveParameter;

/**
 * Everything an adapter needs to reach a node, and nothing else.
 *
 * A value object rather than the Eloquent model, so an adapter cannot
 * write to the database, cannot reach the customer through a relation, and
 * cannot be handed something it has no business holding.
 *
 * The secret is marked sensitive so that a stack trace does not print it.
 * It still never goes in a log line: `SecretRedactor` is the safety net,
 * not the plan.
 */
final readonly class ServerConnection
{
    public function __construct(
        public string $id,
        public string $hostname,
        public ?string $ipAddress,
        public int $port,
        public string $username,
        #[SensitiveParameter]
        public string $secret,
        public bool $secure = true,
        public ?string $region = null,
    ) {}

    /**
     * Never print the secret, whatever prints this object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'hostname' => $this->hostname,
            'port' => $this->port,
            'username' => $this->username,
            'secret' => '[redacted]',
        ];
    }

    public function baseUrl(): string
    {
        return ($this->secure ? 'https://' : 'http://').$this->hostname.':'.$this->port;
    }
}
