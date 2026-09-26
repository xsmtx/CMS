# Phase D — Reliability Plan

Status: planned
Date: 2026-09-26
Previous: `phase-c-plan.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §15, §16, §26
Umbrella: `advanced-operations-plan.md` §9 (D)

Phase A built the graph, B made something outside answer, C let this platform
change a device. D is the phase about **things going wrong**: noticing, saying
so, and being able to prove afterwards what happened and what it cost.

---

## 1. What ships

1. **Alerts** — a rule an operator writes, evaluated against what the platform
   already knows, raising and clearing a record.
2. **Incidents** (§15) — the human thing: a title, a state, a timeline, the
   services and customers underneath, and the alerts that belong to it.
3. **Impact** — `ImpactSummary` from Phase A, on an incident.
4. **Status page** (§15) — the public surface, per brand.
5. **SLA credits** (§15) — a credit note, never an edit to an issued invoice.
6. **Postmortems** (§15) — a document hanging off a resolved incident.
7. **Maintenance windows** (§16) — planned, announced, and suppressing alerts.
8. **Push and deep links** (§26) — a notification channel and links that land
   on a resource the person may open.

## 2. The decisions

### An alert is not an incident, and neither is a ticket

Three records, and merging any two of them would lose something:

- An **alert** is a machine observation with no opinion: a metric crossed a
  line, a check failed, an adapter stopped answering. It raises itself and it
  clears itself. Nobody is assigned to an alert.
- An **incident** is a human saying "this is a thing". It has a state somebody
  moves, a timeline somebody writes, and an impact figure. Many alerts belong
  to one incident, and an incident may have none — half of them start with a
  customer's phone call.
- A **ticket** is a conversation with a customer. It already exists (ADR 0030)
  and it is not this. An incident links to tickets; it does not become one.

The honest version of this rule: an operator is woken by an alert, opens an
incident, and closes the alert when the disk is bigger. If one record did all
three, the platform would either have to decide when a machine observation
becomes a human problem — which it cannot — or ask somebody to acknowledge
four hundred disk warnings a week, which is how people learn to acknowledge
without reading.

### Core raises alerts from what it already knows

No adapter is required. The installation already holds: telemetry readings and
their staleness, adapter health, the health checks, failed operations, failed
automation runs and unreachable servers. A rule is a question about those, and
Phase B's `CapacityForecast` means "this disk fills in nine days" is one too.

`Capability::AlertsRead` stays for a monitoring system's own alerts, which
arrive alongside rather than instead — a Prometheus alert an operator already
tuned is worth more than a rule this platform guessed at, and neither is the
whole picture.

### A rule is rows an operator edits, not constants

The same decision tax, dunning and placement got. Core ships **no rules at
all** and the empty state says so: an installation that woke somebody at three
in the morning because of a threshold nobody chose is an installation whose
alerts get turned off.

### An alert de-duplicates on the thing, not on the moment

One open alert per (rule, subject). A disk that crosses 90% forty times in an
hour is one alert with a count, not forty rows — the guard against repeating
is state, as it is for dunning steps and renewals (ADR 0031). It clears when
the condition stops being true, and the row is kept.

### Notifying is not the alert's job

`NotificationEvent` and `Notifier` already exist (ADR 0029) and an alert raises
an event like everything else. A rule that sent its own mail would be a second
place that knows about opt-outs, locales and delivery records.

### The status page is public, and it is the brand's

A separate surface from the storefront but the same rules: `CurrentBrand`
decides what it says, a reseller's page is theirs, and the boundary comes from
the installation rather than from an actor. What is published is a deliberate
subset — an incident is public only when somebody says so, because "we are
investigating a database problem" is a sentence a company chooses to publish.

### An SLA credit is a credit note

ADR 0023 froze the issued invoice and ADR 0024 made the ledger the truth. A
credit for an outage is therefore a credit note against the invoice for the
period, raised from the incident with the incident's id on it — never an edit,
never a discount applied retroactively to a line.

### A maintenance window suppresses alerts, and says it did

Not "silences": the alert is still raised, still recorded and still visible,
and it carries the window that suppressed its notification. An operator asking
"did anything happen during the maintenance" must get the true answer, and a
platform that dropped the observation could not give it.

## 3. Schema (core)

- `alert_rules` — organization, name, subject kind, metric or check, comparison,
  threshold, window, severity, enabled, notify flag.
- `alerts` — organization, rule, subject (morph), state, severity, first seen,
  last seen, occurrences, cleared at, suppressed-by window, incident.
- `incidents` — organization, reference, title, state, severity, started,
  detected, resolved, summary, public flag, brand, postmortem.
- `incident_updates` — the timeline: incident, actor, state at the time, body,
  public flag, written at.
- `incident_impacts` — the frozen figure at resolution: services, customers,
  recurring by currency. Frozen for the same reason an invoice is: the graph
  moves, and an impact recomputed in March is not the impact anybody acted on.
- `maintenance_windows` — organization, title, starts, ends, state, public
  flag, affected nodes.
- `sla_credits` — incident, customer, invoice, credit note, minor units,
  currency, reason.

Every one carries `organization_id` and `BelongsToOrganization`.

## 4. Authorization

`reliability.alerts.view`, `reliability.alerts.manage`,
`reliability.incidents.view`, `reliability.incidents.manage`,
`reliability.maintenance.manage`, `reliability.credits.issue` (high risk — it
moves money).

Support holds the two `view` permissions and `incidents.manage`: the person
answering "is it just me?" is the person who should be able to open an
incident.

## 5. Sequence

Alerts first, because an incident with nothing to attach is a form. Then
incidents and their timeline, then impact, then the status page, then credits
and postmortems, then maintenance windows, then push.

**Alerts and incidents are in** (2026-09-26) — see the result note at the end
of this document.

## 6. Not in this phase

- **Rolling maintenance and drain/undrain**, which need C's device work and F's
  load balancers.
- **Alert routing and escalation policies.** On-call rotation is a product of
  its own and §15 does not ask for it; `Notifier` already decides who hears
  about what.
- **An anomaly detector.** A threshold somebody chose is a threshold they can
  explain at three in the morning; a model is not, and `CapacityForecast`
  already refuses to answer more often than it answers for the same reason.

## 7. The standing limitation

Unchanged, and now more pointed: the alerts core raises are about readings no
real monitoring system has ever taken, through adapters that have never spoken
to the thing they adapt. The rules and the evaluation are proven against
fixtures. The first real page at three in the morning is still somebody's first
real page.
