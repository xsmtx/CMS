<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * One rule in a firewall policy, flattened to what every vendor has.
 *
 * **Order is a field, not the array's index.** A policy is evaluated top to
 * bottom and the first match wins, so the position is the single most
 * important thing about a rule - and an adapter that returned them in the
 * order its JSON happened to iterate would be an adapter whose policy read
 * correctly and meant something else. `position` is what the device itself
 * calls the sequence, and a caller sorts by it rather than trusting the list.
 *
 * The address and service fields are **strings as the device wrote them**, not
 * parsed. A FortiGate says `LAN_SUBNET` and means an address object defined
 * elsewhere; resolving that would be this platform reimplementing a vendor's
 * object model, getting it wrong, and showing an operator a policy that does
 * not match the one on the box. What is shown is what the device said.
 *
 * `disabled` is separate from absence for the same reason `PortState::Disabled`
 * is: a rule somebody switched off is a decision, and hiding it would hide the
 * decision.
 */
final readonly class FirewallRule
{
    /**
     * @param  list<string>  $sources
     * @param  list<string>  $destinations
     * @param  list<string>  $services
     */
    public function __construct(
        public string $id,
        public int $position,
        public FirewallAction $action = FirewallAction::Unknown,
        public ?string $name = null,
        public bool $enabled = true,
        public array $sources = [],
        public array $destinations = [],
        public array $services = [],
        public ?string $sourceInterface = null,
        public ?string $destinationInterface = null,
        public bool $logged = false,
        /** How many packets the device says have matched, where it counts. */
        public ?int $hitCount = null,
    ) {}
}
