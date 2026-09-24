<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Domain\Infrastructure\Exceptions\InvalidResource;

/**
 * What a node in the graph is.
 *
 * **Not an enum, on purpose**, and it is the one place in this domain where
 * that is true. Core knows four kinds because it owns four kinds of row: an
 * organization, a server, a service and a customer. A rack, a VM, a switch
 * port, an IP prefix, a storage volume and a backup repository are all things
 * a *module* owns, and an enum in core naming hardware core does not model is
 * how a modular platform stops being one (ADR 0043).
 *
 * So a kind is a validated string. The validation matters more than it looks:
 * a kind reaches a URL segment, a translation key and a filter, so anything
 * that is not a plain lowercase word can leave all three — the same rule a
 * module slug lives under.
 */
final readonly class ResourceKind
{
    public const string Organization = 'organization';

    public const string Server = 'server';

    public const string Service = 'service';

    /**
     * Not projected, and here because an IP assignment will point at one.
     *
     * A customer's services are in the graph; the customer is not, because CRM
     * already answers "what does this customer have" and a second answer would
     * eventually disagree with the first. What the graph will need a customer
     * node for is the other direction — who held this address in March — which
     * arrives with IPAM in Phase C.
     */
    public const string Customer = 'customer';

    /**
     * The kinds core itself projects, in the order they nest.
     *
     * Used by the Explorer to decide what to show at the top level, and by the
     * projection to know what it is responsible for. A module's kinds are not in
     * this list and must not be: the projection would otherwise believe it owned
     * rows it has never seen, and retire them.
     *
     * @var list<string>
     */
    public const array Core = [
        self::Organization,
        self::Server,
        self::Service,
    ];

    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^[a-z0-9]+(_[a-z0-9]+)*$/', $value) !== 1) {
            throw InvalidResource::badKind($value);
        }

        return new self($value);
    }

    public function isCore(): bool
    {
        return in_array($this->value, self::Core, strict: true);
    }

    /**
     * Where the wording lives.
     *
     * A module's kind has no entry in `lang/`, which is not a failure: the
     * presenter falls back to the kind itself, so an unregistered kind reads
     * as `switch_port` rather than as a missing translation key. Visible, and
     * legible enough to act on — the rule the notification placeholders
     * already follow.
     */
    public function labelKey(): string
    {
        return 'infrastructure.kinds.'.$this->value;
    }
}
