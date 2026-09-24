# Phase A — Foundation Plan

Status: complete — see `phase-a-result.md`
Date: 2026-09-24
Previous: `phase-17-result.md` (handoff #1 complete)
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §2, §14, §27, §28 Phase A, §29
Umbrella: `advanced-operations-plan.md`
ADRs: [0043](../adr/0043-the-resource-graph-is-edges-not-facts.md)

Handoff #2's phases are lettered, so its result documents are too. This is the
first phase of the second handoff, and it builds nothing an operator asked for —
it builds the four things every later phase needs to exist first.

---

## 1. What ships

**The Resource Graph.** `resource_nodes`, `resource_edges`, `resource_metrics`,
and the four read models that make them worth having: what is inside this, what
does this sit on, who is affected if it fails, and who had this before.

**A projection of what core already knows.** `ProjectCoreResources`, an
automation task under `RecordedRun`, keeps nodes and edges in step with
organizations, servers and services. (Not customers: CRM already answers "what
does this customer have", and a second answer would eventually disagree.) This is what makes the graph real
on day one with no module installed and no adapter configured: an operator opens
Explorer and sees their own servers with the services on them and the revenue
underneath.

**The capability registry.** `Capability`, `CapabilitySet`, the
`InfrastructureAdapter` contract every adapter implements, `MonitoringProvider`
as the first capability contract, and `resource_adapters` — the row that says
whether an adapter may write.

**Telemetry normalization.** `MetricKind` with canonical units, `MetricSample`,
and `RecordSamples`, which refuses a metric it cannot name rather than storing it
as it arrived.

**The operations UI shell.** An Infrastructure nav group with three screens:
Explorer, Adapters, Telemetry.

**SDK 1.1.** `ExtensionPoint::InfrastructureAdapter`,
`ModuleType::Infrastructure`, and `Module::adapters()` with a default in
`BaseModule` — a minor bump by `Sdk`'s own rule, and the first time that rule has
been used.

**A worked example outside core.** `modules/example/file-probe` registers a
`MonitoringProvider` that reads measurements from a JSON file something else
writes. It needs a path rather than a credential, and it is the only proof that
the registry works from where a third party stands — the same job
`modules/example/status-board` does for the rest of the SDK. (Planned as a probe
of the platform's own numbers; a module has no read access to core's database,
which is the SDK working as intended, so it reads a file instead.)

## 2. The decisions

**The seam is core; the capability is a module.** §0 says every advanced
capability is an optional module, and the graph cannot be one: it is what the
modules write into. So the tables, the traversal, the registry and the normalizer
are core, and every node kind beyond the three core projects is a string a
module registers. A small provider installs nothing and still gets the three
screens, because the nodes are servers and services they already have.

**`ResourceKind` is not a closed enum.** Core projects `organization`, `server`
and `service` because it owns those rows. A `rack` is a module's word. An enum in
core naming hardware core does not model is how a modular platform stops being one
— so the kind is a validated string with core's own constants on a class, and a
module declares its own.

**The projection is an automation task, not a listener.** A run asks a question
about rows — "which servers have no node, which nodes have no server" — which
means a scheduler that was down for three days catches up rather than missing
three days of inventory permanently (ADR 0031). Events would have been the
obvious choice and the wrong one: there is no `ServerCreated` event, adding six
of them to make inventory work would be six new obligations on unrelated code, and
a missed event is a node that never appears with nothing to notice it.

**Nothing in Phase A needs a credential**, and the credential vault is therefore
Phase B. Phase 17 already learned this with file upload rules: a seam with nothing
behind it is a seam that is wrong in a way only the first caller discovers. The
example adapter reads a file at a path an operator typed; Phase B's first real
adapter needs a token, and that is when the vault lands.

**Write capability is a row, not a class.** An adapter declares what it *can* do;
`resource_adapters.writes_enabled` decides whether it may. Default false, turning
it on is audited, and `AdapterRegistry` refuses a write capability the row has not
enabled. The same package should be a read-only window on one installation and a
control plane on another, by the operator's choice rather than by which package
they installed.

**An unnameable metric is refused, not stored.** `RecordSamples` maps a source's
name onto a canonical one — `node_cpu_seconds_total` and Zabbix's
`system.cpu.util` are the same question — and drops what it cannot map, counting
it. A table that accepts any metric name is a time-series database nobody sized,
and §14 is explicit that the time series stays outside.

**Depth is bounded at twelve, and the walk is one query per level.** Discovered
infrastructure contains cycles, and an unbounded walk over rows an adapter wrote is
a denial of service with extra steps. A recursive CTE would be one round trip
instead of a handful and would carry no global scope — the organization filter
would have to be hand-written in SQL and kept right forever, which is not a trade
this platform makes.

## 3. Schema

As set out in `advanced-operations-plan.md` §4. The parts worth restating because
they are the ones a reviewer should check:

- `resource_nodes` is unique on `(organization_id, kind, node_key)` — that is what
  makes a second projection run a no-op.
- `resource_edges` is append-only. `ended_at` is the only column ever updated, and
  an edge's `organization_id` is its **container's**, which is what stops a
  customer walking upward into the provider's estate.
- `resource_metrics` is unique on `(resource_node_id, metric)`. One row per pair;
  a sample upserts.
- `resource_adapters` holds the operator's decisions about an adapter — enabled,
  writes enabled, last health, last seen — and never a credential.

## 4. Authorization

Four permissions in `CorePermissions`:

| Slug | What it opens |
| --- | --- |
| `infrastructure.resources.view` | Explorer, the tree, impact, dependency paths |
| `infrastructure.adapters.view` | the Adapters screen |
| `infrastructure.adapters.manage` | enabling an adapter, and turning writes on — high risk |
| `infrastructure.telemetry.view` | the Telemetry screen |

`infrastructure.adapters.manage` is declared high risk, which means Phase 17's
`RequireRecentAuthentication` applies to it without anything new being written.
The roles that get them: Administrator (all), Operations/Support read-only where
it helps a ticket. Phase 8's lesson — a role called Support that held none of the
support permissions — is a per-phase obligation, not a one-off fix.

Client-area exposure: **none in Phase A.** A customer has no infrastructure
screen. The graph's boundary work is still tested from the customer's side,
because "a customer cannot walk up from their service" is the claim ADR 0043
makes and an untested claim about a boundary is a hope.

## 5. Tests

- **Isolation.** Two organizations with servers and services; every read model
  answers only within the boundary, and a customer resolving their own service
  node finds no edge to the server.
- **Idempotency.** `ProjectCoreResources` twice: the second run writes nothing
  and is still recorded (ADR 0031's two required tests, one of which is "make one
  row fail and assert the rest completed").
- **History.** Move a service to another server; assert the old edge closed, the
  new one opened, and `OwnershipHistory` shows both.
- **Cycles and depth.** Link two nodes to each other and assert the traversal
  terminates.
- **Capability refusal.** An adapter declaring a write capability whose row has
  `writes_enabled = false` is refused by the registry, and the refusal is not the
  same answer as "the adapter cannot do that".
- **`health()` returns no configuration.** The Phase 9 rule, asserted again for
  the new shape.
- **Lazy loading.** At least two of everything, per `LazyLoadingTest`'s standing
  rule; every screen that renders a customer name uses `displayNameWith`.
- **The example module.** Install, enable, register an adapter, receive samples,
  disable — driven entirely from outside core.
- **Vitest** over the new Vue screens and the nav map addition.

## 6. Not in this phase

- The credential vault and JIT access (§17) — Phase B and C, for the reason in §2.
- Any real provider adapter. The example reads this platform's own database.
- Any API endpoint. §26's staff surface is a security decision that gets its own
  ADR in Phase I, and a customer-facing infrastructure endpoint is not a thing
  that should exist.
- Capacity rollups, thresholds and alerting. A threshold with no real samples
  behind it is a number somebody invented.
- The Infrastructure Operations Center views from §3 that need metrics a real
  adapter has not yet delivered. The shell is here; the dashboards follow the
  data.
