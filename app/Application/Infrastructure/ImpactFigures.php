<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Application\Reports\MoneyByCurrency;

/**
 * Who notices if this fails.
 *
 * The revenue figure is `MoneyByCurrency` and never a number, because there is
 * no exchange rate anywhere in this platform and inventing one for a dashboard
 * would be inventing one for an invoice a week later. An outage across 9,000 EUR
 * and 4,000 TRY of recurring revenue is reported as both, and an operator reads
 * that perfectly well.
 *
 * `nodesByKind` is here because "12 services" and "12 services on 3 servers in 1
 * rack" are different sentences and the second is the one that tells somebody
 * where to go.
 */
final readonly class ImpactFigures
{
    /**
     * @param  array<string, int>  $nodesByKind
     */
    public function __construct(
        public int $services,
        public int $customers,
        public MoneyByCurrency $recurring,
        public array $nodesByKind,
        /** How deep the walk went before it ran out of edges or out of depth. */
        public int $depth,
        /**
         * Whether the walk stopped because it hit the depth limit rather than
         * because it ran out of edges.
         *
         * Reported rather than hidden: an operator reading "affects 40 services"
         * should know when the real answer is "at least 40".
         */
        public bool $truncated = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->services === 0 && $this->customers === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?string $locale = null): array
    {
        return [
            'services' => $this->services,
            'customers' => $this->customers,
            'recurring' => $this->recurring->toArray($locale),
            'nodes_by_kind' => $this->nodesByKind,
            'depth' => $this->depth,
            'truncated' => $this->truncated,
        ];
    }
}
