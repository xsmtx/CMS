# Phase D — Reliability, what shipped

Status: complete
Date: 2026-09-27
Plan: `phase-d-plan.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §15, §16, §26

Everything §1 of the plan listed is in, with one item declined and the reason
recorded. Phases E to J are not started.

---

## 1. What shipped

| § | Thing | Where |
| --- | --- | --- |
| 15 | Alert rules and alerts | `alert_rules`, `alerts`, `EvaluateAlertRule`, `EvaluateAlerts`, `Admin/Reliability/Alerts` |
| 15 | Incidents and their timeline | `incidents`, `incident_updates`, `Incidents`, `Admin/Reliability/Incident(s)` |
| 15 | Impact, frozen at resolution | `incident_impacts`, `ImpactSummary` |
| 15 | Public status page | `/status`, `PublicStatus`, `PublicStatusLevel`, `themes/storefront/core/views/status.blade.php` |
| 15 | SLA credits | `sla_credits`, `IssueSlaCredit`, `AffectedCustomers` |
| 15 | Postmortems | `incidents.postmortem`, `Incidents::recordPostmortem()` |
| 16 | Maintenance windows | `maintenance_windows`, `MaintenanceWindows`, `Admin/Reliability/Maintenance` |
| 16 | Operations calendar | `OperationsCalendar`, `Admin/Reliability/Calendar` |
| 26 | Deep links | `DeepLink`, `tests/Feature/DeepLinkTest.php` |
| 26 | Push channel | **declined — see §3** |

Permissions: `reliability.alerts.view`, `reliability.alerts.manage`,
`reliability.incidents.view`, `reliability.incidents.manage`,
`reliability.credits.issue`, `reliability.maintenance.manage`. Support holds
the views, `incidents.manage` and `maintenance.manage`; credits are not
Support's.

## 2. The decisions this phase actually made

**An alert is not an incident and neither is a ticket.** A machine
observation with no opinion; a human saying "this is a thing"; a conversation
with a customer. Merging any two would make the platform decide when an
observation becomes a human problem — which it cannot — or ask somebody to
acknowledge four hundred disk warnings a week. There is deliberately no
`acknowledged` state for the second reason.

**Core ships no alert rules and computes no SLA percentage.** Both are the
decision tax, dunning and placement already made: an installation that woke
somebody at three in the morning over a threshold nobody chose is one whose
alerts get turned off in a fortnight, and a credit percentage invented here
would be a commercial promise made on a seller's behalf.

**What core *does* answer is who.** `AffectedCustomers` walks an incident's
alerts to their nodes, the nodes to the services under them, and the services
to their customers and invoices. Every monitoring system can say a machine was
down; none of them knows the eleven services on it belonged to nine customers.

**The impact is frozen, and then the evidence stops moving too.** ADR 0023's
reasoning applied to a figure somebody quotes in a credit conversation:
attaching or detaching an alert after resolution is refused, because a stored
number beside rows it was not computed from is a number nobody can reconcile.

**A credit is a credit note.** One path moves money (`IssueCreditNote`);
`sla_credits` is a link saying which incident it was for, not a second ledger.

**A maintenance window suppresses the message, never the observation.** The
alert is raised, counted and on the screen carrying the window that held it.
An operator asking "did anything happen during the maintenance" gets the true
answer.

**No stored state on a window.** Whether it is running is a question about its
own two timestamps — the plan's §3 sketch of a `state` column is corrected
here, following `access_grants`. Being called off is the one thing a clock
cannot say, so `cancelled_at` is a column and cancelling one that already ran
is refused.

**The status page stays up in maintenance mode** and is `noindex`. What it
publishes is a deliberate subset: no impact figure, no hostnames, no
operator's name, not even the reference. The banner does not move for planned
work.

## 3. What was declined, and why

**`NotificationChannel::Push`.** A member nothing implements is a member
nothing can set: `ChannelRegistry` would hold no entry, `Notifier` would
deliver nothing and `notification_deliveries` would record nothing — the
`AddonStatus` rule through a different door. What "push" means for an operator
being woken is already `Sms` and `Chat` (SDK 1.3), both implemented by
modules. Web push is a subscription table, a VAPID keypair and a service
worker, and the provider half belongs in a module.

**Rolling maintenance, drain and undrain.** They need C's device work and F's
load balancers, exactly as §6 of the plan says.

**Alert routing and escalation.** On-call rotation is a product of its own.
`SendAlertNotifications` tells every staff member and says so in a comment.

## 4. Bugs the browser found that 2,047 tests did not

- **`IncidentState::Monitoring` drew the unknown mark.** `statusTone()` maps
  status *words*; `info` is a *tone*. `asTone()` now reads a server-decided
  tone, and the alerts screen had the identical line working by coincidence.
- **`AppConfirm` never emitted `close`**, which every screen listens for — so
  backing out of any confirmation in the product left it unable to be reopened
  without a page reload. Roughly forty screens.
- **`AppCheckbox` takes `description` and two screens passed `hint`**, so the
  sentence under a checkbox never rendered. `ComponentPropsTest` now derives
  each primitive's props and refuses one primitive's prop passed to another.
- **`Incident::updates()` used `latest()`**, and `created_at` has one-second
  resolution — two updates in one second came back in database order, which on
  a status page is a timeline reading backwards.
- **The order-confirmation email linked to an order's ULID** where the route
  binds on its number. The first message this platform ever sends a customer,
  404. `DeepLink` and `DeepLinkTest` exist because of it.
- Three buttons labelled with the heading above them; a public time with no
  zone; a date range printed twice; a column header borrowed from a metric
  strip; a state repeated beside the update that already said it.

## 5. The standing limitation

Unchanged and more pointed than before: the alerts core raises are about
readings no real monitoring system has ever taken, through adapters that have
never spoken to the thing they adapt. The rules, the evaluation, the
suppression and the notification are proven against fixtures and driven in a
browser. The first real page at three in the morning is still somebody's first
real page.
