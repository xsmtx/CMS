# Phase A — Foundation Result

Status: complete
Date: 2026-09-24
Plan: `phase-a-plan.md`
Umbrella: `advanced-operations-plan.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §2, §14, §27, §28 Phase A, §29
ADRs: [0043](../adr/0043-the-resource-graph-is-edges-not-facts.md),
[0044](../adr/0044-mobile-is-react-native-and-a-separate-artefact.md)

The first phase of the second handoff. It builds nothing an operator asked for and
everything the other nine phases stand on.

---

## 1. What shipped

- **The Resource Graph** — `resource_nodes`, `resource_edges`, `resource_metrics`,
  `resource_adapters`, one writer (`ResourceGraph`) and four read models
  (`ResourceTree`, `ImpactSummary`, `DependencyPath` via `above()`,
  `OwnershipHistory`).
- **`ProjectCoreResources`** — an automation task that keeps the graph in step
  with organizations, servers and services. This is what makes the graph real on
  an installation with no modules: the nodes are rows that have been there since
  Phase 6.
- **The capability registry** — `AdapterArea` (23 members, one per contract §27
  asks for), `Capability` (54 members, read and write distinct), `CapabilitySet`,
  `AdapterDescriptor`, `AdapterHealth`, `RateLimits`, the `InfrastructureAdapter`
  contract and `MonitoringProvider` as the first capability contract.
- **Telemetry normalization** — `MetricKind` (24 measurements with their aliases),
  `MetricUnit` (canonical unit per dimension, and the arithmetic),
  `SampleNormalizer`, `RecordSamples`, and `CollectTelemetry`, the sweep that asks
  every enabled monitoring adapter what it knows.
- **The operations UI shell** — an Infrastructure nav group with Explorer,
  Telemetry and Adapters; four permissions; `lang/en` and `lang/tr`; and three
  runbook entries, because a graph that is empty and a telemetry feed that has
  stopped are both silent failures.
- **SDK 1.1** — `ExtensionPoint::InfrastructureAdapter`,
  `ModuleType::Infrastructure`, `Module::adapters()` with a default in
  `BaseModule`. The first use of `Sdk`'s own rule: a method with a default and an
  enum member modules only read is a minor bump, so every existing module keeps
  working.
- **`modules/example/file-probe`** — the worked example, outside core, driven end
  to end by `tests/Feature/ExampleFileProbeTest.php`.
- **Setup is a page rather than a dropdown**, and every owner-only screen is on
  it. Asked for mid-phase; see §4.

## 2. The decisions

**The seam is core; the capability is a module.** §0 says every advanced
capability is an optional module, and the graph cannot be one — it is what the
modules write into. So the tables, the traversal, the registry and the normalizer
are core, and every node kind beyond the three core projects is a string a module
registers. An installation with no modules still gets three working screens,
because the nodes are its own servers and services.

**`ResourceKind` is the one open vocabulary in the domain, and `Relation` is
deliberately closed.** A kind is a noun and core cannot know every noun. A
relation is a semantic: the impact query has to know which edges mean "inside", and
an edge whose relation nobody recognises is an edge that query would silently skip
— which is how a screen tells an operator that an outage affects nobody.

**An edge belongs to its container, and that is a privacy decision.** The test
that matters is written from the customer's side: a customer resolving their own
service node finds no edge above it and cannot see the server. Containment points
downward *so that* the boundary hides the container from the contained.

**The traversal is one query per level, not a recursive CTE.** MariaDB would do it
in one round trip; hand-written SQL carries no global scope, and the organization
filter would have to be written out by hand and kept right forever. Twelve queries
on a screen somebody opened deliberately is the price of making an unscoped lookup
impossible rather than unlikely.

**The projection is a task, not a set of listeners.** There is no `ServerCreated`
event; adding six of them so that inventory works would be six new obligations on
unrelated code, and a missed event is a node that never appears with nothing to
notice it. A run asks about rows, so a scheduler that was down all night catches
up (ADR 0031).

**A node key is the subject's id, not its hostname.** A hostname is what an
adapter knows and it is also not unique — two servers sharing one would silently
become a single node, because the unique key is exactly what makes a second run
idempotent. How a real adapter's inventory finds a node key is Phase B's problem
and the answer is a mapping, not a guess.

**Write access is a row, not a class.** An adapter declares what it *can* do;
`resource_adapters.writes_enabled` decides what it *may*, defaults to false, and
turning it on is audited with the capabilities named one by one. The screen's
confirmation is the destructive level of the ladder and the route asks for a
password again (Phase 17). The same package is a read-only window on one
installation and a control plane on another, by the operator's choice.

**Switching an adapter off is a different endpoint, and deliberately not
guarded.** Allowing writes and turning an adapter off were one endpoint at first,
which put the password challenge in front of both — and turning an adapter off is
what somebody does *while* an incident is running. `PUT …/adapters/{id}/writes`
carries `auth.recent`; `PUT …/adapters/{id}` does not, and a test pins that.

**Health here means "is anything telling us about this".** There are no
thresholds in this phase and a screen that invented one would be inventing an
alert. A node with fresh readings is `ok`, one whose every reading has outlived its
declared freshness is `degraded`, and a node nobody reports on stays `unknown` —
which is the count the Telemetry screen exists to show.

**No translated sentence is written into a database column.** `health_message`
stays null for staleness: a message stored in the language of whichever scheduler
run wrote it is a message the next operator cannot read, and the screen already
has `sampled_at`.

## 3. What the tests found

**The worked example found a hole in the normalizer within a minute of running.**
The probe file says `cpu_percent: 40`; the normalizer read a bare number in the
metric's canonical unit and stored a CPU ratio of *forty*. The fix is not a guess
— it is the opposite of one: a source that writes its unit into the metric's name
(`cpu_percent`, `memory_used_mb`, `rx_mbps`) has *said* what the number is, and
`MetricKind::unitFromName()` now reads it, checked against the metric's dimension
so `cpu_bytes` is still a mismatch rather than a conversion. This is exactly what
a worked example outside core is for.

**Two places implemented the same narrowing rule and disagreed.**
`ResourceAdapter::permittedCapabilities()` gave a disabled adapter its reads;
`RegisteredAdapter::permitted()` gave it nothing. A test asserted the second and
caught the first. Whichever answer is right, two of them is the bug — the rule is
now `ResourceAdapter::narrow()` and both callers ask it. Disabled beats allowed,
because an operator switching an adapter off mid-incident must not also have to
revoke its writes.

**`memory_used` was missing from its own alias list**, which the conversion test
found. A conservative alias table is right; an incomplete one is just wrong.

**Module registration did not know about adapters.** `InspectModule` lists what a
module registered by extension point, and a new point has to be added there or the
row says a module registered nothing — which would make uninstall unable to refuse
without loading the package it is being asked to remove (ADR 0038). The example
module's test caught it.

## 4. Setup is a page now

Asked for mid-phase, and it is the right shape. The Setup dropdown was eight
undifferentiated links; it is now one destination with a sentence under each
screen and a count beside it, which is the difference between "Roles" and "Roles —
6 defined, who may do what".

Every **owner-only** screen moved there too, into a section of its own: Modules,
Servers, Connect, Licence and Import. All five are about who somebody *is* rather
than what they may do, all five are ways an administrator account becomes control
of the estate, and they were previously spread between Setup and Utilities where a
day-to-day administrator met them by accident.

**The page's gate moved to the doors, and the doors already had it.** The hub used
to be super-admin only; an administrator now reaches it for Products and Roles and
simply does not see the owner-only section. Nothing was relaxed:
`ModuleController`, `InfrastructureController`, `ConnectController`,
`LicenceController` and `ImportController` each refuse on their own — which is why
`assertSuperAdminFor` is a static method they all call rather than a middleware
somebody could forget to list, and why opening the hub was safe.

**The rows stayed in the nav map although the dropdown went**, because the command
palette is built from that map. Somebody who knows they want Roles presses ⌘K and
types it rather than learning where it moved. `hidden` on the group is what keeps
them out of the rail and in the palette, and a Vitest case asserts both.

## 5. Not in this phase

- **The credential vault.** Nothing in Phase A needs a credential: the graph is
  inventory over existing rows, and the example adapter reads a path an operator
  typed. Phase B's first real adapter is the first caller and the vault lands with
  it. A seam nobody has used is a seam that is wrong in a way only the first caller
  discovers — Phase 17 learned that with file uploads.
- **Any API endpoint.** §26's staff surface is a security decision with its own
  ADR in Phase I, and a customer-facing infrastructure endpoint is not a thing
  that should exist.
- **Thresholds, alerting and capacity rollups.** A threshold with no real samples
  behind it is a number somebody invented.
- **An adapter health sweep.** Health is a button in this phase. A sweep polling
  twenty devices every five minutes before anybody had configured a timeout would
  be this platform's first denial of service against its own operator; Phase B adds
  it, respecting the rate limits an adapter declares.
- **The topology diagram.** A picture is what everyone asks for and what nobody
  can read at four hundred nodes. The Explorer answers the three questions an
  operator actually asks — what does this sit on, what is on it, who notices if it
  stops — and a diagram can be drawn over the same data in Phase C.

## 6. Standing limitations

- **No real monitoring system has ever answered this code.** `FileProbe` reads a
  file; Prometheus and Zabbix are Phase B, and the first deployment must treat
  each adapter as unproven, exactly as the handoff #1 provider adapters are.
- **No screen has been driven in a browser.** Still true, and it matters more
  every phase.
- **The graph has never held more than a few dozen nodes.** The traversal is
  bounded and indexed for the access patterns the screens use; a real installation
  with four thousand services is the first honest test of that.

## 7. Gates

Pint, Rector, PHPStan level 8, Pest on MariaDB, ESLint, Prettier, vue-tsc,
Vitest, Vite build, `platform:openapi --check` — all green.
**1316 Pest tests, 83 Vitest tests.**
