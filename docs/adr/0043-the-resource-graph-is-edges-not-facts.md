# 0043 — The Resource Graph is edges, not facts

Status: accepted
Date: 2026-09-24

## Context

Handoff #2 §2 asks for a Hosting Digital Twin: one model that can walk
`Datacenter → Room → Row → Rack → PDU → Physical Server → Hypervisor → VM →
Hosting Server → Account → Service → Customer`, and three other spines beside it
(network, storage, delivery). It must answer impact questions — which customers
and how much revenue a failure touches — and historical ones, such as who held an
IP address in March.

Almost every node in those chains already exists somewhere, or will. A server is
a `servers` row. A service is a `services` row with a customer, a product, a
price and a next due date. A rack will be a row in a DCIM module. A VM will be
whatever Proxmox says it is. The graph is not a new inventory; it is a claim about
how the inventories relate.

That distinction is the whole decision, because there are two ways to build this
and one of them fails slowly.

**The failing way is a materialised twin**: one table per node kind with its
attributes copied in, refreshed by a sweep. It demos beautifully. Six months
later the twin says a service is active and `services.status` says terminated,
and nobody can say which screen to believe. It is the same mistake as caching a
paid amount on an invoice and then editing it by hand — which this platform
already refuses to do (ADR 0024: the ledger is the truth and the cache is
rebuilt from it).

The other option was a purpose-built graph database. Neo4j answers "all paths
from this port to a customer" better than SQL ever will. It also means a second
datastore to back up, to secure, to keep consistent with MariaDB, and to install
before a hosting provider can see their own servers on a screen. The traversals
this product actually needs are bounded — twelve levels, tens of thousands of
nodes — and SQL walks them in a handful of queries.

## Decision

**The graph stores identity and relationships. It never stores the facts the
owning table already holds.**

Three tables, and no more:

- `resource_nodes` — `kind`, `node_key`, a `label` that is explicitly a display
  cache, and a nullable `subject_type`/`subject_id` pointing at the row this node
  *is*. Unique on `(organization_id, kind, node_key)`, which is what makes
  discovery idempotent rather than careful.
- `resource_edges` — `from_node_id`, `to_node_id`, `relation`, `observed_at` and
  a nullable `ended_at`. **Append-only**; `ended_at` is the only column ever
  updated.
- `resource_metrics` — one row per `(node, metric)`: the latest sample, its
  canonical unit, when it was taken and how long it stays fresh.

And four read models over them, each walking one query per level to a bounded
depth: `ResourceTree`, `ImpactSummary`, `DependencyPath`, `OwnershipHistory`.

## Consequences

**A node is never the answer to a question about a service.** Opening something
in the Explorer loads the real row through the same application service the
admin screen uses. The graph gets you *to* it and tells you what it hangs off;
it does not tell you the price. This is the rule that stops the twin drifting,
because there is nothing to drift: a label that is stale is a cosmetic bug, and a
status that is stale is impossible when the status is not stored here.

**History is free rather than a feature.** §5 requires historical IP ownership as
a first-class fact. With append-only edges it is `where ended_at is not null` —
the same trick the ledger plays. A reassignment closes one edge and opens
another, so the question "who had this address in March" is a query, not a
migration nobody wrote.

**An edge belongs to the organization of its container, and that is a privacy
decision.** A server belongs to the provider; the service on it belongs to the
customer; the edge `server —hosts→ service` belongs to the provider. A customer
walking upward from their own service finds an edge the boundary does not show
them, and learns nothing about the machine they share. The provider, being an
ancestor, sees both ends and can answer the impact question. So direction is not
only modelling: **containment points downward so that the boundary hides the
container from the contained.** Reversing an edge for convenience would be a
disclosure, which is why `ResourceGraph::attach()` takes `container` and
`contained` as separate, named arguments rather than two nodes and a relation —
and why it refuses outright when the contained node is not inside the container's
subtree.

**Depth is bounded and explicit, because discovered data contains cycles.** Two
switches each reporting the other as upstream is an ordinary Tuesday, and an
unbounded walk over rows an adapter wrote is a denial of service with extra steps.
The default is twelve, which covers the longest spine in §2 with room to spare.

**The traversal is one query per level rather than one recursive CTE, and the
reason is the boundary.** A hand-written `WITH RECURSIVE` carries no global scope,
so the organization filter would have to be written into the statement by hand and
kept right forever; going level by level through Eloquent means the scope applies
to every hop for free. Twelve queries on a screen somebody opened deliberately is
a price worth paying to make an unscoped lookup impossible rather than unlikely.

**Retired, never deleted.** A node whose subject is gone gets `retired_at`. A
terminated service is exactly what an incident review needs to see, and deleting
the node would silently shorten every historical path through it.

**Revenue enters the graph as `MoneyByCurrency` and never as a number.**
`ImpactSummary` reads `services.recurring_minor` and groups by currency, the
shape Phase 16 introduced. There is no exchange rate here, because there is no
exchange rate anywhere in this platform. An outage affecting 9,000 EUR and 4,000
TRY of recurring revenue is reported as both, and an operator can read that.

**Telemetry is a `double`, and that is not the money rule being bent.** A CPU
ratio is a measurement; a measurement may be approximate. Money is an amount and
may not be. The two never meet in this schema: `resource_metrics` holds no
monetary value, and the rule stays enforceable because it stays absolute
everywhere it applies.

**Every node kind beyond core's is a module's.** `ResourceKind` is not a closed
enum for that reason — core projects `organization`, `server` and `service`
because it owns those rows, and a `rack` or a `switch_port` is a string a module
registers. A customer is deliberately *not* a node: CRM already answers "what does
this customer have", and a second answer would eventually disagree with the first. The alternative would be an enum in core naming
hardware core does not model, which is how a modular platform stops being one.

## Alternatives rejected

- **A materialised twin with copied attributes.** Drifts, and the drift is
  invisible until two screens disagree in front of a customer.
- **A graph database.** A second datastore, and an operational burden, to make
  bounded traversals faster than they need to be. Revisit if a real installation
  exceeds a hundred thousand nodes; the read models are four classes and the
  storage behind them can be replaced without touching a screen.
- **A recursive CTE.** One round trip instead of a handful, in exchange for
  writing the tenancy boundary out by hand in SQL. See above.
- **Per-kind tables with foreign keys and joins.** Honest, and it cannot express
  a relationship between two kinds neither of which core knows about — which is
  every relationship a network module will discover.
- **Edges as a nullable pair of columns on each owning table** (`services.rack_id`
  and so on). No history, a migration per relationship, and a customer-owned
  table carrying a provider-owned identifier.
