<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing;

use App\Domain\Billing\Contracts\PaymentGateway;

/**
 * The gateways this installation has configured.
 *
 * A registry rather than a container tag, so a module can add one at
 * runtime in Phase 12 and so the set an operator sees is the set that is
 * actually usable — a gateway missing its credentials never gets
 * registered, rather than being offered and then failing at the till.
 */
final class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    /**
     * @param  iterable<PaymentGateway>  $gateways
     */
    public function __construct(iterable $gateways = [])
    {
        foreach ($gateways as $gateway) {
            $this->register($gateway);
        }
    }

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function find(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->gateways[$key]);
    }

    /**
     * @return array<string, PaymentGateway>
     */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * The gateways that can actually take this currency, for the screen
     * where a customer chooses.
     *
     * @return array<string, PaymentGateway>
     */
    public function forCurrency(string $currencyCode): array
    {
        return array_filter(
            $this->gateways,
            static fn (PaymentGateway $gateway): bool => $gateway->capabilities()->supportsCurrency($currencyCode),
        );
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->gateways);
    }
}
