# Advanced Operations — plan of record

Status: plan. Phase A complete; **Phase B started** (2026-09-26); C to J not
started.
Date: 2026-09-24
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` (all sections)
Required by: handoff #2 §30

This is the document §30 asks for before any of handoff #2 is built. It maps
every module in that handoff onto the domains handoff #1 already has, proposes
the Resource Graph schema and its read models, fixes the adapter capability
conventions, says which telemetry stays in somebody else's database, and breaks
the work down in dependency order.

Two ADRs come out of it and are written alongside:
[ADR 0043](../adr/0043-the-resource-graph-is-edges-not-facts.md) for the Digital
Twin and [ADR 0044](../adr/0044-mobile-is-react-native-and-a-separate-artefact.md)
for the mobile framework.

**Phases 0 to 17 of handoff #1 are complete.** That is the floor this stands on:
the organization boundary, RBAC, the audit log, the event bus, the operations
table, `RecordedRun`, the module SDK, entitlements, the notification pipeline and
the error envelope all exist and are not rebuilt here.

---

## 1. What this handoff is

Handoff #2 is twenty-four product families over roughly two hundred screens. It
is larger than handoff #1. The one sentence in its §0 that keeps it tractable is
this: **the platform is the context, correlation, orchestration and
guarded-control layer.** It is not a monitoring system, a NetFlow collector, a
log store, a backup engine or a firewall manager. Every one of those exists,
mature and free, and each of them is somebody's whole company.

So the shape of every feature here is the same three moves:

1. **Adapt** — read from the system that already knows (Prometheus, Zabbix,
   FortiGate, Veeam, Proxmox, PowerDNS).
2. **Correlate** — attach what it said to a customer, a service, an invoice, a
   contract, an incident, a rack unit and a revenue number. This is the part
   nobody else can do, because nobody else has the billing database.
3. **Guard** — when an operator wants to change something out there, put
   authorization, validation, a diff, an approval, a backup, an audit record and
   a rollback around it.

A feature that does not do at least one of those three is not this platform's
job. That test is what keeps the roadmap from becoming an attempt to rewrite
Zabbix.

## 2. The rule that decides the architecture: what is core, what is a module

§0 says *build every advanced capability as optional modules; do not force
DCIM/network features on small providers.* Taken literally that would put the
Resource Graph in a module, and it cannot be: the graph is what every other
module writes into, and modules cannot depend on each other's tables without
becoming one large module with a dependency list.

The resolution is the one handoff #1 already reached for gateways, registrars and
provisioning (ADR 0038, ADR 0039):

> **The seam is core. The capability is a module.**

Concretely:

| In core | As a module |
| --- | --- |
| `resource_nodes`, `resource_edges`, `resource_metrics` and the traversal | every node kind beyond the ones core already owns |
| the `Capability` enum and the adapter registry | every adapter that declares capabilities |
| the metric normalizer and its canonical units | every source of samples |
| the Infrastructure nav group, Explorer, Adapters, Telemetry | every screen about racks, firewalls, IP pools, backups |
| the credential vault contract (Phase B) | every credential's actual provider |

A provider with fifty shared-hosting accounts and no datacenter installs nothing
and sees three screens listing the servers and services they already have. A
provider with four racks installs `dcim` and the same Explorer grows a
`Datacenter → Room → Row → Rack → U` spine. Nothing in core mentions a rack.

The graph over core rows is not "forcing DCIM on small providers": the nodes are
servers, services and customers, which every installation already has. What is
optional is everything that needs a second system to be true.

## 3. Module map — every family, and the handoff #1 domain it extends

Slugs are module slugs (`modules/<vendor>/<slug>`, `ModuleType::Infrastructure`
unless noted). "Extends" names the existing `app/Domain` context whose rows the
feature correlates against; where it says *core*, the work lands in core because
it is a seam.

| # | Handoff #2 family | Extends (handoff #1) | Module slug | Phase |
| --- | --- | --- | --- | --- |
| §2 | Resource Graph / Digital Twin | Provisioning, Crm, Organizations | *core* (`app/Domain/Infrastructure`) | A |
| §27 | Adapter capability registry | Modules (SDK), Provisioning | *core* | A |
| §14 | Telemetry normalization | Health, Operations | *core* | A |
| §3 | Infrastructure Operations Center | Provisioning, Billing | *core* shell + `infra-center` | A shell, B views |
| §14 | Monitoring adapters | Health | `monitoring-prometheus`, `monitoring-zabbix`, `monitoring-librenms` | B |
| §4 | Smart placement | Provisioning (`PlacementStrategy`) | *core* extension + `placement-scoring` | B |
| §4 | Capacity planning | Provisioning, Automation | `capacity` | B |
| §23 | Global search / palette | Crm, Billing, Support, Provisioning | *core* (extends the existing palette) | B |
| §5 | IPAM | Provisioning, Domains | `ipam` | C |
| §6 | Network & security operations | *new*, correlates Provisioning | `network`, `network-fortigate`, `network-juniper`, `network-snmp` | C |
| §6 | Guarded configuration workflow | Operations (ADR 0032), Access | `network` (uses core change records) | C |
| §7 | Routing / flow / DDoS | *new*, correlates Crm + Billing | `flow`, `ddos` | C |
| §15 | Incidents / status / SLA / postmortem | Support, Notifications, Billing (credits) | `incidents`, `status-page` | D |
| §16 | Maintenance / change / drift / vulnerability | Automation, Operations | `maintenance`, `drift`, `vulnerability` | D |
| §13 | Abuse / mail / reputation | Support, Crm, Provisioning | `abuse`, `mail-ops` | E |
| §13 | Evidence preservation | Support, Access (retention) | `abuse` | E |
| §8 | DNS / SSL / WAF / CDN | Domains, Provisioning | `dns`, `certificates`, `waf-cdn` | E |
| §12 | Backup | Provisioning, Automation | `backup`, `backup-veeam`, `backup-jetbackup` | F |
| §9 | Storage / DB / cache / load balancer | Provisioning, Health | `storage`, `database-telemetry`, `loadbalancer` | F |
| §10 | Virtualization / bare metal / BMC | Provisioning | `hypervisor-proxmox`, `hypervisor-vsphere`, `bmc-redfish` | F |
| §11 | DCIM / hardware / power / environment | Organizations, Provisioning | `dcim`, `pdu-apc`, `ups`, `environment` | G |
| §11 | Remote Hands | Support, Operations | `dcim` | G |
| §18 | WordPress fleet | Provisioning | `wordpress-fleet` | G |
| §22 | Reconciliation engine | Provisioning (ADR 0026), Billing | `reconciliation` | H |
| §21 | Revenue leakage / orphans / noisy neighbour | Billing, Crm, Provisioning | `commercial-intelligence` | H |
| §21 | Cost / profitability | Billing, Resellers | `cost-model` | H |
| §19 | Automation builder | Automation (ADR 0031), Operations | `automation-builder` | H |
| §20 | AI operations assistant | everything, read-only | `ai-assistant` | H |
| §17 | Credential vault / JIT access | Identity, Access, Operations | *core* contract + `vault-hashicorp` | B contract, C JIT |
| §24 | Vendor / licence / contract / procurement | Billing, Organizations | `vendors`, `procurement` | J |
| §25 | Usage metering | Billing (`Metering` contract) | *core* contract + `metering-*` | F contract, J sources |
| §25 | IaC / Kubernetes | Operations | `iac`, `kubernetes` | J |
| §25 | Operations calendar | Automation, Support | `calendar` | D |
| §26 | Mobile applications | Api (ADR 0033) + a new staff surface | separate artefact, see ADR 0044 | I |

Three entries in that table are worth reading twice, because they are the
decisions rather than the listing:

**Smart placement extends what exists.** `PlacementStrategy` and the server
chooser are already in `app/Domain/Provisioning`. §4 wants a scored, explainable
choice over thirteen inputs. That is a strategy implementation plus a persisted
reason, not a new subsystem — and the reason string is the deliverable, because
"why did it pick that node" is the question an operator asks at 2am.

**Global search extends the palette.** `AdminLayout.vue` already has ⌘K over
every nav destination. §23 wants records too. The palette stays one component
and gains a server-side source; a second search box would drift from the first
inside a month.

**Metering is a contract in core and a module per source.** §25 says usage
billing feeds the core billing domain through a stable metering contract with
immutable invoiced usage snapshots. The snapshot is core and append-only — it is
a number on an invoice and an invoice is frozen (ADR 0023). Where the number came
from is a module.

## 4. The Resource Graph

The full reasoning is [ADR 0043](../adr/0043-the-resource-graph-is-edges-not-facts.md).
The schema:

### 4.1 `resource_nodes`

| Column | Type | Note |
| --- | --- | --- |
| `id` | ULID | |
| `organization_id` | ULID | owned table; `BelongsToOrganization` |
| `kind` | string(48) | `server`, `service`, `customer`, `rack`, `vm`, `switch_port`… |
| `node_key` | string(191) | stable identity within (organization, kind) |
| `label` | string(191) | **display cache only** |
| `source` | string(48) | `core`, or the adapter key that discovered it |
| `subject_type` / `subject_id` | string(96) / ULID, nullable | the row this node *is*, when one exists |
| `health` | string(16) | cache written by the normalizer; `unknown` by default |
| `attributes` | json, nullable | only what the graph needs to draw itself |
| `discovered_at`, `last_seen_at` | timestamp | |
| `retired_at` | timestamp, nullable | a node is retired, never deleted |

`unique (organization_id, kind, node_key)` is what makes discovery idempotent —
a sweep that runs twice writes the same rows, which is ADR 0031's rule applied to
inventory.

`subject_type`/`subject_id` is a pointer, not a copy. Everything anyone wants to
know about a service is on the service; the node carries identity, position and
a cached label so that a tree can be drawn in one query.

### 4.2 `resource_edges`

| Column | Type | Note |
| --- | --- | --- |
| `id` | ULID | |
| `organization_id` | ULID | **the organization of `from_node`** — see below |
| `from_node_id`, `to_node_id` | ULID | direction is containment: container → contained |
| `relation` | string(48) | `contains`, `hosts`, `powers`, `connects`, `serves`, `assigned_to`, `depends_on` |
| `source` | string(48) | |
| `attributes` | json, nullable | port number, power feed A/B, prefix length |
| `observed_at` | timestamp | |
| `ended_at` | timestamp, nullable | the only column ever updated |

Append-only with a closing timestamp, exactly like the ledger (ADR 0024). §5
requires historical IP ownership as a first-class fact; with this shape it is not
a feature, it is the rows that have an `ended_at`.

**An edge belongs to the organization of its container, and that is a privacy
decision.** A server belongs to the provider, the service on it belongs to the
customer, and the edge `server —hosts→ service` belongs to the provider. So a
customer walking up from their own service finds an edge they may not see and
learns nothing about the machine they are on; the provider, being an ancestor,
sees both ends and can answer the impact question. Direction is therefore not
only modelling: **containment points downward so that the boundary hides the
container from the contained.**

### 4.3 `resource_metrics`

One row per `(node, metric)` — the present, not the past.

| Column | Type |
| --- | --- |
| `resource_node_id`, `organization_id` | ULID |
| `metric` | string(64), canonical name |
| `unit` | string(16), canonical unit |
| `value` | double |
| `sampled_at` | timestamp |
| `stale_after_seconds` | unsigned, nullable |
| `source` | string(48) |

`unique (resource_node_id, metric)`. A sample upserts. `value` is a `double`
and that is not a violation of the money rule: telemetry is a measurement, money
is an amount. The rule money has — integer minor units, no conversion at display
time — exists because a cent must not be invented by arithmetic. A CPU ratio has
no such obligation. **No monetary value is ever stored here**; when the graph
answers a revenue question it reads `services.recurring_minor` and returns
`MoneyByCurrency`, the shape Phase 16 already introduced.

### 4.4 Read models

Four, and they are the reason the graph exists. Each walks **one query per
level**, to a bounded depth. MariaDB has had `WITH RECURSIVE` for years and would
do it in one round trip — but hand-written SQL carries no global scope, so the
organization filter would have to be written into the statement by hand, forever.
An unscoped lookup is the one class of bug this platform will not trade anything
for, and the price is a handful of extra queries on a screen somebody opened
deliberately.

- **`ResourceTree`** — the downward closure of a node. What is in this rack.
- **`ImpactSummary`** — the answer to "if this fails, who notices": counts of
  services and customers reachable downward, and their recurring revenue as
  `MoneyByCurrency`. This is the number that turns an alert into a priority.
- **`DependencyPath`** — the upward chain from a service to the datacenter. The
  "what does this sit on" a support engineer needs mid-ticket.
- **`OwnershipHistory`** — the ended edges for one node and relation. Who had
  this IP in March.

Depth is **bounded and explicit** (default 12). An unbounded recursive query over
operator-supplied data is a denial of service with extra steps, and a cycle in
discovered data is not hypothetical — two switches each reporting the other as
upstream is a Tuesday.

## 5. Adapter capability conventions

§27 asks for twenty-three capability-oriented contracts rather than one enormous
provider interface. The conventions, fixed here so that twenty-three contracts
written over nine phases end up looking like one library:

1. **One contract per capability area**, named `<Area>Provider`, in
   `app/Domain/Infrastructure/Contracts`. An adapter implements as many as it
   honestly supports. `FirewallProvider` and `SwitchProvider` are separate even
   though one FortiGate answers both, because a device that is only a switch
   exists.

2. **Every adapter also implements `InfrastructureAdapter`**: `key()`, `name()`,
   `vendor()`, `capabilities(): CapabilitySet`, `limits(): RateLimits`,
   `health(): AdapterHealth`. The registry needs those answers before it is
   willing to call anything else.

3. **A capability is a question core asks before it calls**, never a promise an
   adapter makes about a method that then throws. This is `ModuleCapabilities`
   from Phase 6 generalised, and it was right there: core offers a suspend button
   only where suspending means something.

4. **Read and write are different capabilities**, not a flag on one. There is
   `Capability::FirewallPolicyRead` and `Capability::FirewallPolicyWrite`. §29
   requires the distinction; this is where it lives.

5. **Write capability is opt-in per adapter instance, not per adapter class.**
   An adapter that *can* change a firewall, installed to look at one, must not be
   able to change it because the SDK supports both. `resource_adapters.writes_enabled`
   defaults to false, turning it on is audited, and the registry refuses a write
   capability the row has not enabled. This is the most important line in this
   section: the same package on two installations should be a read-only window on
   one and a control plane on the other, by the operator's choice.

6. **Version compatibility is the adapter's answer, not core's guess.**
   `AdapterHealth` carries the remote version it saw and whether it is supported;
   an unsupported version degrades the adapter rather than failing the screen.

7. **Rate limits are declared and respected by the caller.** An adapter says "30
   requests per minute"; core throttles. An adapter that self-throttles is an
   adapter whose author has written a queue, badly.

8. **`health()` never returns configuration.** The Phase 9 rule, restated
   because it will be tempting here: not a DSN, not a host, not a token prefix.
   A test asserts it.

9. Everything handoff #1 already requires of an outbound call still applies:
   timeout, bounded retries with backoff, correlation id, sanitised structured
   error, never inside a database transaction, and `SecretRedactor` on the way to
   the log.

## 6. Which telemetry stays external

The question §30 asks, answered as a table. The principle: **MariaDB holds the
present and the durable; the time series lives where time series live.**

| Data | Where it lives | Why |
| --- | --- | --- |
| Metric time series (CPU, RAM, bandwidth, per-interface counters) | Prometheus / Zabbix / LibreNMS | Millions of samples a day per installation. A chart asks the adapter; the platform stores the latest value and the threshold state. |
| Logs | Loki / ELK / OpenSearch / SIEM | §14 says it outright. A log table in MariaDB is a table that has to be truncated on a Sunday. |
| Flow records (NetFlow / sFlow / IPFIX) | the flow collector | §7 prefers external scalable flow storage. The platform stores the *conclusions*: top talkers for a window, traffic attributed to a customer. |
| WAF / CDN request logs | the WAF / CDN backend | §8 says raw high-volume telemetry stays in specialist backends. |
| Raw SNMP counters | the poller | A counter is only interesting as a rate, and the rate is what arrives. |
| Packet captures, console recordings | nowhere near this database | Evidence storage is object storage with a retention policy (§13). |

| Data | Ours, and why |
| --- | --- |
| Latest sample per node and metric | It is what every screen renders, and it is bounded by node count. |
| Threshold state and its transitions | An alert has a lifecycle, an owner and an incident. That is a record, not a sample. |
| Daily capacity rollups | Forecasting needs a year of daily points, which is small and bounded. Phase B. |
| Incidents, changes, maintenance, postmortems, abuse cases, remote-hands tasks | The platform is the system of record for these; nobody else has them. |
| Invoiced usage snapshots | A number on a frozen invoice must be provable in three years (ADR 0023, §25). |
| The graph itself | Nodes and edges are inventory, and inventory is durable. |

A consequence worth naming: **the platform may be wrong about the present and
must never be wrong about the past.** A stale metric is a visible, dated,
recoverable condition — which is why `sampled_at` and `stale_after_seconds` are
columns, and why the Telemetry screen exists to show what has stopped arriving.

## 7. Secrets, and why the vault is not Phase A

§0 requires secrets to use a central encrypted secret abstraction, optionally
Vault/KMS-backed. Handoff #1 has no such abstraction: it has `encrypted` casts on
six columns (`servers.secret`, `webhook_endpoints.secret`, the two-factor
secrets, module config) and `SecretRedactor` on the logging path. That was right
for six columns and is wrong for twenty-three adapter families.

So a `SecretStore` contract in core — put, get, rotate, destroy, with an
`EncryptedDatabaseSecrets` default and a `vault-hashicorp` module later — is real
work, and it is **Phase B, not Phase A**. The reason is the lesson Phase 17
learned about file uploads: **nothing in Phase A needs a credential.** The graph
is inventory over rows core already has, the registry describes adapters, and the
worked example adapter reads a file path an operator typed. A vault built before
its first caller is a seam with nothing behind it, and a seam nobody has used is a
seam that is wrong in a way only the first caller discovers.

Phase B's first adapter is the first caller: a Prometheus adapter needs a token. The vault lands with it, in the same
phase, and JIT access (§17) follows in Phase C where the thing being accessed
exists.

## 8. Mobile

[ADR 0044](../adr/0044-mobile-is-react-native-and-a-separate-artefact.md) decides
React Native with Expo and TypeScript, as a **separate artefact** consuming the
API rather than a directory in this repository.

The finding that matters for the roadmap, and it is a gap rather than a
preference: **§26's staff app needs a staff API surface, and handoff #1
deliberately did not build one.** ADR 0033 puts an API token's contact on the
`client` guard so that every policy behaves identically for a browser and a
token; there is no staff-guard equivalent, and inventing one is a security
decision rather than a mobile one. Phase I opens with that ADR. Nothing about
mobile is half-built before it.

What core owes mobile, and when:

- a `push` notification channel and per-category preferences (§26) — Phase D,
  alongside incidents, because `on_call` and `incident` are the categories that
  justify it;
- deep links resolving to authorized resources — Phase D;
- step-up authentication — **already shipped**, Phase 17's
  `RequireRecentAuthentication`;
- device/session inventory and remote revocation — Phase I with the staff API;
- short-lived access plus refresh rotation — Phase I; today's tokens do not
  expire, which is fine for a server-to-server integration and not fine on a
  phone.

## 9. Dependency-aware task breakdown

Arrows are hard dependencies: the right cannot start until the left is done.

```text
A  Foundation
   graph schema ─┬─ projection of core rows ─┬─ Explorer + impact
                 ├─ capability registry ─────┼─ Adapters screen
                 └─ metric normalizer ───────┴─ Telemetry screen
                                              └─ SDK 1.1 (adapters + kinds)

B  Core ops            needs A
   SecretStore ─── monitoring adapters ─── health from real samples
                                        └─ capacity rollups ─── forecasting
   placement scoring (needs metrics)    └─ Infrastructure Center views
   global record search (needs only A's kinds)

C  Network             needs A, B(secrets)
   IPAM ─── prefix/assignment history (graph edges)
   device adapters ─── topology (graph) ─── interfaces/VLAN/BGP
   change records ─── guarded config workflow ─── JIT access
   flow/DDoS (needs IPAM for attribution)

D  Reliability         needs A, B(monitoring)
   alerts ─── incidents ─── impact (A's ImpactSummary) ─── status page
                        └─ SLA credits (Billing) ─── postmortems
   maintenance windows ─── rolling maintenance (needs C drain, F LB)
   push channel + deep links
   operations calendar (needs incidents, maintenance, contract stubs)

E  Security            needs A, D(incidents)
   abuse cases ─── evidence preservation ─── guarded actions (Provisioning)
   mail ops ─── reputation
   DNS/SSL fleet ─── certificate expiry (Domains) ─── WAF/CDN context

F  Data platform       needs A, B(secrets)
   backup adapters ─── protection coverage ─── unprotected detection
   storage / DB / cache / LB adapters ─── drain-undrain (feeds D)
   hypervisor / BMC adapters ─── power actions (guarded)
   Metering contract

G  Datacenter          needs A, F(hypervisor, for what hardware carries)
   DCIM spine ─── rack elevation ─── hardware inventory
   PDU/UPS/environment adapters ─── power path in the graph
   Remote Hands ─── barcode/QR lookup (feeds I)
   WordPress fleet

H  Intelligence        needs A, B, E, F
   reconciliation (expected vs actual) ─── remediation proposals
   orphan detection ─── revenue leakage ─── cost/profitability
   noisy neighbour (needs per-service metrics)
   automation builder ─── AI assistant (needs everything above as evidence)

I  Mobile              needs staff API ADR, D(push)
   staff API surface ─── customer app ─── staff app ─── approvals

J  Advanced            needs H
   vendors/contracts/procurement, IaC/GitOps, Kubernetes views
```

Ordering principles, so that a later phase can be resequenced without guessing:

- **Nothing reads a metric before the normalizer exists** — A gates everything.
- **Nothing calls a provider before `SecretStore` exists** — B gates C and F.
- **Nothing computes impact before the graph is populated** — A's projection
  gates D's incidents.
- **Nothing is remediated before it is reconciled** — H follows E and F, because
  a remediation proposal needs an actual state to compare against.
- **Mobile is last but one**, because a phone app over a changing API is two
  migrations, and because the staff API is a security decision that benefits from
  every guarded action already existing.

## 9a. Phase B, what is in so far (2026-09-26)

Four of B's items are in, and they landed in the order §9 requires — the vault
first, because the first adapter is its first caller.

- **`SecretStore`**, with `SecretReference` (area/kind/owner) and
  `EncryptedDatabaseSecrets`. Writes audited, reads not; writing over a value
  is a rotation; the audit row names the reference and never the value. A
  `vault-hashicorp` module can answer the same contract later.
- **`monitoring-prometheus`**, the first adapter family that reads a real
  system. Address in module configuration, token in the vault, read at the
  moment of the call. Four instant queries across every target rather than one
  query per host.
- **The credential UI** on the Adapters screen: write-only, behind the
  password challenge, saying only whether one is stored and when it changed.
- **The health sweep** Phase A deferred, pacing itself by each adapter's own
  declared limits and asking a question about rows rather than about the clock.
- **The daily point** (`resource_metric_days`) and `CapacityForecast`, which
  answers when a resource runs out and declines whenever the honest answer is
  that it cannot know.
- **Global search reaches the graph**: a hostname or an IP out of somebody
  else's ticket now finds the resource.

Still open in B: placement scoring over real metrics, the Infrastructure
Center views, and a screen for the capacity answer. Nothing in C, F or the
rest has been started, and the standing limitation is unchanged — no real
monitoring system has ever answered this code.

## 10. Phase A, precisely

See `phase-a-plan.md` and `phase-a-result.md`.

1. `resource_nodes`, `resource_edges`, `resource_metrics`, `resource_adapters`.
2. `app/Domain/Infrastructure`: `ResourceKind`, `Relation`, `Capability`,
   `CapabilitySet`, `MetricKind`, `MetricSample`, `AdapterDescriptor`,
   `AdapterHealth`, `RateLimits`, and the contracts `InfrastructureAdapter` and
   `MonitoringProvider`.
3. `app/Application/Infrastructure`: `ProjectCoreResources` (a `RecordedRun`
   automation task), `UpsertNode`, `LinkNodes`, `RetireNode`, `RecordSamples`,
   `ResourceTree`, `ImpactSummary`, `DependencyPath`, `OwnershipHistory`,
   `AdapterRegistry`, `SetAdapterWrites`.
4. SDK 1.1: `ExtensionPoint::InfrastructureAdapter`, `ModuleType::Infrastructure`,
   `Module::adapters()` with a default in `BaseModule`.
5. The Infrastructure nav group with Explorer, Adapters and Telemetry; four
   permissions; `lang/en` and `lang/tr`.
6. A worked example module outside core that registers an adapter, so that the
   seam is proven from where a third party stands.

**Not in Phase A**: the credential vault (§7 above), any real provider adapter,
any API endpoint (§8 above), capacity rollups, and every screen from §3 that
needs samples a real adapter has not yet delivered.

## 11. Standing risks

- **The provider adapters of handoff #1 have never talked to their real
  providers**, by the owner's deliberate sequencing. Handoff #2 adds twenty-three
  more adapter families. The mitigation is structural rather than hopeful: a
  capability is asked before it is called, an unsupported version degrades rather
  than fails, and write access is off until an operator turns it on.
- **No screen in this product has been driven in a browser by its author.**
  Handoff #2 is where that stops being survivable: a topology diagram and a rack
  elevation cannot be verified by reading a stylesheet.
- **Handoff #2's surface is larger than handoff #1's.** Phases B to J are not a
  commitment to build all of it. The module map exists so that any single family
  can be built, or not built, without the rest moving.
