<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * How one node relates to another, and it is a closed list.
 *
 * `ResourceKind` is deliberately open and this is deliberately not, which is
 * worth explaining because the two sit next to each other. A kind is a noun and
 * core cannot know every noun. A relation is a *semantic*: the traversal has to
 * know which edges mean "this is inside that" in order to answer an impact
 * question, and an edge with a relation nobody in core recognises is an edge the
 * impact query would silently skip. Silently skipping an edge is how a screen
 * tells an operator that an outage affects nobody.
 *
 * Seven members cover every spine handoff #2 §2 draws. If an eighth is ever
 * needed, adding it is a minor SDK bump and a decision — which is the point.
 */
enum Relation: string
{
    /** Physical or logical containment: a rack contains a server, a pool contains a volume. */
    case Contains = 'contains';

    /** A machine runs a workload: a hypervisor hosts a VM, a server hosts a hosting account. */
    case Hosts = 'hosts';

    /** Electrical: a PDU outlet powers a device. */
    case Powers = 'powers';

    /** Network adjacency: a switch port connects to a NIC. */
    case Connects = 'connects';

    /** Delivery: a load balancer serves a web backend, a nameserver serves a zone. */
    case Serves = 'serves';

    /** Allocation with a history: an IP address is assigned to a server. */
    case AssignedTo = 'assigned_to';

    /** Everything else that would break: a service depends on a database. */
    case DependsOn = 'depends_on';

    /**
     * Whether an impact question follows this edge downward.
     *
     * All of them except `assigned_to` and `connects`, and the exclusions are
     * the interesting part. An IP address being assigned to a server does not
     * mean the address fails when the server does — the address is a fact about
     * allocation, and following it would count the same customer twice through
     * two paths. `connects` is symmetric in practice, and a switch port is not
     * "inside" a NIC.
     */
    public function propagatesImpact(): bool
    {
        return match ($this) {
            self::Contains, self::Hosts, self::Powers, self::Serves, self::DependsOn => true,
            self::AssignedTo, self::Connects => false,
        };
    }

    /**
     * Whether history is the point of this relation.
     *
     * An assignment that ended is the answer to "who had this address in
     * March", which §5 requires. Containment that ended is a server that was
     * moved, which is also worth keeping — so this is really about which
     * relations the ownership-history screen offers, rather than about which
     * ones are append-only. They all are.
     */
    public function isAllocation(): bool
    {
        return $this === self::AssignedTo;
    }

    public function labelKey(): string
    {
        return 'infrastructure.relations.'.$this->value;
    }
}
