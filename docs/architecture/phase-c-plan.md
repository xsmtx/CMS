# Phase C — Network Plan

Status: planned
Date: 2026-09-26
Previous: `phase-b-result.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §5, §6, §7, §17, §28
Phase C, §29
Umbrella: `advanced-operations-plan.md`

Phase A built the graph, Phase B made something outside this repository answer.
Phase C is the first phase where this platform could **change** something outside
itself, which is why half of it is about refusing to.

---

## 1. What ships

1. **IPAM** (§5) — pools, prefixes, gateways, VLANs, addresses, reservations,
   assignments, reverse DNS, and assignment history. In core; see §2.
2. **Network device contracts** (§6) — the capability contracts behind the
   `network_device`, `firewall`, `switching` and `routing` areas Phase A already
   declared, plus **one** vendor module that answers them.
3. **Topology** (§6) — the discovery writes device, interface, VLAN, prefix and
   address nodes into the graph, so Internet → Transit → Router → Firewall →
   Core → Access switch → Port → Server → VM → Service → Customer is the
   containment walk the graph already does.
4. **Change records and the guarded configuration workflow** (§6) —
   `Request → Validate → Diff → Authorize → Approval(optional) → Backup → Apply
   → Verify → Complete/Rollback`, persisting requester, approver, reason,
   ticket, the exact diff and the result.
5. **JIT access** (§17) — a grant that expires, and the temporary firewall rule
   that is the same record with a device behind it.
6. **DDoS events** (§7) — the event record, the attribution to a customer and a
   service through IPAM, and the impact figure. **Not** the flow series.

## 2. The decisions

### IPAM is core, and the umbrella table is wrong about it

`advanced-operations-plan.md` §3's table assigns §5 to an `ipam` module. That
cannot be built as written, and the reason is worth stating once because it
governs the rest of handoff #2:

**A module cannot draw a screen.** `ExtensionPoint` has `Navigation` and
`Widget`, and `NavigationItem` carries a *path* — but nothing in
`ModuleServiceProvider` or `ModuleLoader` registers routes or view paths, so the
path a module names has to be a route core already serves. The worked example is
the evidence: `modules/example/status-board` registers a nav item pointing at
`/admin/health`, a core screen. A module ships adapters, calculators, channels
and permissions; it does not ship pages.

§5 is mostly pages, plus mandatory ownership history. So IPAM is core, and this
plan is where that is decided. Three more reasons the same way:

- **Ownership history lives in the graph**, which is core and append-only with a
  closing timestamp — "who had this address in March" is already a `where`
  clause (ADR 0043), and a module re-implementing it would be a second answer.
- **An address is billable inventory hanging off a service.** Core's provisioning
  and billing own services; a module core had to ask about addresses would be
  core depending on a module, which the layering refuses.
- Every panel this product is measured against has IP management in the box.

What stays a module is what was always going to be: **the vendor-specific code**
that talks to a device. `network-fortigate`, `network-juniper`, `network-snmp`,
exactly as the monitoring adapters are.

### An address is a row; an assignment is a row of its own

The first draft of this plan said the assignment was a graph edge, because the
graph is already append-only with a closing timestamp and "who had this address
in March" is already a `where` clause there. **It cannot be**, and the reason is
a rule from ADR 0043 that only shows up when you try: an edge belongs to its
container's organization and the graph refuses an edge whose ends are in
different subtrees. An address belongs to the seller's range and the service
holding it belongs to the customer, so "service contains address" is exactly the
edge that is refused — and inverting it would let a customer walk up from their
address to the seller's prefix, which is the privacy decision the direction rule
exists to make.

So `ip_assignments` is its own append-only table, owned by the seller, closed
rather than deleted. The graph still gets prefixes and devices when topology
lands, because those are all on the provider's side of the boundary.

ADR 0043's other half holds unchanged: `ip_addresses` owns the address, its
prefix, its reverse DNS, its state and its note — the graph never stores the
facts the owning table holds.

### An address row exists because somebody acted on it

A /64 holds eighteen quintillion addresses. Materialising a row per address is
impossible there and merely wasteful in a /16, so a row appears when an address
is assigned, reserved, quarantined or given a reverse-DNS name, and *free* is
the prefix's own range minus the rows in it. That is a range query on the binary
column, which is most of the reason the binary column exists.

### Text to read, bytes to compare

An address is stored twice: the canonical text an operator reads, and a
fixed-width binary form to compare on. Sorting, "is this inside that prefix" and
"what is the next free address" are range questions, and IPv6 is 128 bits, which
no integer column holds. Sixteen bytes, IPv4 mapped into the v6 space, so one
column and one index answer both families. **The text is derived from the bytes
on write, never parsed on read** — two spellings of one IPv6 address
(`2001:db8::1` and `2001:0db8:0000::0001`) must not become two rows.

### A prefix with assignments is not deletable

The same shape as a module in use: the refusal names what is in the way. An
operator who wants the prefix gone releases the addresses first, deliberately.

### A change is a record before it is an action

The guarded workflow is a state machine on a `network_changes` row, and the
apply is an **operation** (ADR 0032) so it is visible before it finishes.
`WatchedDispatch` opens the operation row before the job reaches a worker,
because a change that never reached one is the failure nobody sees.

Two rules the workflow's shape already implies and that will be written into the
code rather than the document:

- **The diff is computed against what the device says now**, immediately before
  the apply, and stored. A diff computed at request time and applied an hour
  later is a diff against a device somebody else has edited.
- **A backup that failed is a change that does not happen.** The order is not a
  suggestion.

`auth.recent` guards Authorize, and `owner` sits above it on the route group —
Phase 17's rule, learned twice already.

### Nothing in core talks to a device

The contracts live in `app/Domain/Infrastructure/Contracts`, the adapters are
modules, and `resource_adapters.writes_enabled` still decides whether a write
capability is even offered. A firewall policy write is the most consequential
thing this platform will ever be able to do; it arrives narrowed by default, off
by default, and audited with the capability named.

### JIT access is a grant with an expiry, and a sweep

Never a timer, never a scheduled revoke that has to run (ADR 0031). A grant is a
row with `expires_at`; the sweep asks which grants are past theirs and not yet
revoked, so a scheduler that was down for three hours catches up instead of
leaving somebody's access open forever. A temporary firewall rule is the same
row with a change record behind it, and its removal is a change record too.

The screen it extends is Connect (`infrastructure.connect`), because that is
already the answer to "a support agent needs access to a thing without being
handed a root password".

### The flow series stays outside

§7 says to prefer external scalable flow storage, and §14's rule already said the
series lives in the monitoring system. So core keeps the **DDoS event** — target,
customer, service, duration, peak, vectors, mitigation, incident link — and the
attribution that makes it meaningful, and asks an adapter for top talkers when a
screen needs them. A NetFlow collector inside a billing database is a
time-series store nobody sized, for the second time.

## 3. Schema (core)

- `ip_pools` — organization, name, kind (v4/v6), purpose
  (infrastructure/customer), note.
- `ip_prefixes` — pool, CIDR text, network and broadcast bytes, prefix length,
  gateway, VLAN, site, state, note. Self-referencing parent for
  supernet/subnet.
- `ip_addresses` — prefix, address text, address bytes, state
  (available/reserved/assigned/quarantined), reverse DNS, note.
- `vlans` — organization, tag, name, site, note.
- `network_changes` — organization, device node, requester, approver, reason,
  ticket, state, diff, backup reference, result, operation id, scheduled and
  expiry timestamps.
- `access_grants` — organization, actor, target node, capability, reason,
  ticket, granted/expires/revoked timestamps, revoker.
- `ddos_events` — organization, target address, customer, service, started,
  ended, peak Gbps, peak Mpps, vectors, mitigation, provider, incident
  reference.

Every one carries `organization_id` and `BelongsToOrganization`. An address
assigned to a customer's service belongs to the seller's subtree, and the
containment direction rule (ADR 0043) means a customer sees their address and
not the prefix it came from.

## 4. Authorization

New permissions: `network.ipam.view`, `network.ipam.manage`,
`network.devices.view`, `network.changes.request`, `network.changes.approve`,
`network.changes.apply`, `network.access.grant`.

`request` and `approve` are separate permissions and a test asserts that the
requester cannot be the approver on a change that requires approval. `apply`
carries `auth.recent`.

## 5. Sequence

IPAM first, because everything else attributes to an address. Then the device
contracts and one module, then topology (which is the contracts writing into the
graph), then change records, then JIT, then DDoS events.

Each step lands with its screens, its translations in both languages, its tests
and its gates, as every phase does.

**The device contracts and the first vendor module are in** (2026-09-26):
`NetworkDeviceProvider`, `FirewallProvider`, `SwitchProvider` and
`RoutingProvider` in `app/Domain/Infrastructure/Contracts`, the eleven value
objects they speak in under `app/Domain/Infrastructure/Network`, and
`modules/infracms/network-fortigate` answering all four.

Three decisions worth keeping:

- **Four contracts, one adapter object.** A FortiGate is a firewall, a switch
  and a router in one chassis, so `FortigateProvider` implements all four —
  which is the case `AdapterArea` was split for. An installation whose box is
  only a firewall still gets the switch and routing reads, answering with
  empty lists, which is the truth rather than an error.
- **The write side has no method, and that is the design.** FortiOS takes a
  policy write over the same API. `Capability::FirewallPolicyWrite` exists and
  no interface declares a method for it, because the write arrives behind the
  guarded workflow with a backup in front of it — and an interface that let
  something call `apply()` first would be the shortcut around the workflow,
  built before the workflow.
- **`DeviceConfiguration::fingerprint()` normalises line endings before
  hashing.** The same configuration read over two transports must not
  fingerprint differently, or the diff step would refuse every apply on that
  box as "somebody else has edited it".

**Topology is in** (2026-09-26): `DiscoverTopology`, the
`AutomationTask::Topology` member and an hourly schedule. It asks each
network-device adapter to describe itself and writes a `network_device` node,
a `device_port` under it, an `ip_address` under each port and a `vlan` where
the same box also answers as a switch.

Four decisions:

- **An adapter is one device**, so `describe()` is handed the adapter's own key
  rather than a list of node keys the way `CollectTelemetry` is. There is no
  inventory of devices to draw a list from, and inventing one would be this
  platform guessing at hardware it has never seen.
- **The serial is the identity**, falling back to the adapter key rather than
  to the hostname. A hostname is changed by whoever last configured the box,
  and a node key that moved would leave the old node behind as a device that
  had apparently vanished.
- **An address hangs off the port, not the device**, which is what makes the
  spine §2 draws reach all the way down — and is the honest direction, since a
  box with two interfaces on one subnet is a box where knowing which one
  matters.
- **It retires only what it wrote**, scoped by `source`. Retiring by kind
  would take a second adapter's ports with it every time this one ran, which
  is the rule `ProjectCoreResources` already states about never believing it
  owns rows it has never seen.

An `ip_address` node is deliberately **not** an `ip_addresses` row. That table
is the seller's plan — what somebody intends to hand out — and a node is what a
box says it is actually wearing. The whole value of discovery is being able to
say they disagree, which is impossible once one has overwritten the other;
reconciling the two is a screen, and a screen is not in this step.

What is left of §6 is the change records.

**IPAM is in** (2026-09-26): `IpAddress` and `IpPrefix` as value objects with
the arithmetic, five tables, `AllocateAddress`, `AssignAddress`, `SavePrefix`,
`PrefixUtilisation`, two screens under `/admin/network/addressing`, two
permissions, and both languages. What is left of §5 is reverse-DNS writing,
which needs the DNS contract in Phase E, and an address handed out by
provisioning rather than by an operator, which is a `PlacementStrategy`-shaped
question this plan leaves to the device work.

## 6. Not in this phase

- **More than one vendor module.** Each is somebody's API and none of them can be
  proven here; the second one is worth nothing until the first has met a real
  device.
- **Flow storage and top-talker history.** The adapter contract, not the series.
- **Incidents.** A DDoS event links to an incident and Phase D is where an
  incident exists; until then the link is a nullable reference.
- **Rolling maintenance and drain/undrain**, which need D and F.
- **Anything that writes to a device without a real device to write to.** The
  write capabilities exist in the registry and stay off; a module that claims one
  is narrowed by its row until an operator turns it on deliberately.

## 7. The standing limitation, restated

No adapter in this product has ever talked to the thing it adapts. Phase C adds
the first contracts whose write side could take a network down, so the same
sentence needs saying more loudly: **the guarded workflow will have been tested
against a fake device only.** The release checklist's rule for provider adapters
applies to every device adapter, and the first real apply on real hardware is an
operator's decision made with a backup in hand.
