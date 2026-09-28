# Phase E — Security, abuse, mail and the certificate fleet: what shipped

Status: complete
Date: 2026-09-28
Plan: `phase-e-plan.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §8, §13

Everything §1 of the plan listed is in. Phases F to J are not started.

---

## 1. What shipped

| § | Thing | Where |
| --- | --- | --- |
| 13 | Abuse cases, their timeline and their evidence | `abuse_cases`, `abuse_case_events`, `abuse_evidence`, `AbuseCases`, `Admin/Security/Abuse(Case)` |
| 13 | The correlation chain, at the time of the report | `AttributeReport`, `Attribution` |
| 13 | Guarded actions against a customer | `abuse_actions`, `AbuseAction`, `auth.recent` on the endpoint |
| 13 | Evidence retention | `ForgetAbuseEvidence`, `AutomationTask::AbuseRetention` |
| 8 | The certificate fleet | `certificates`, `CertificateProvider`, `RecordCertificates`, `CollectCertificates`, `Admin/Security/Certificates` |
| 8 | Zone health | `DnsProvider`, `ZoneCheck`, `InspectZone`, `zone_findings`, `InspectZones`, `Admin/Security/ZoneHealth` |
| 13 | Mail operations, as telemetry | eight `MetricKind` members, through the normalizer that already existed |
| 13 | Sending reputation | `ReputationProvider`, `BlocklistListing`, `reputation_listings`, `RecordListings`, `CheckReputation`, `Admin/Security/Reputation` |

Permissions: `security.abuse.view`, `security.abuse.manage`,
`security.abuse.act`, `security.certificates.view`, `security.dns.view`,
`security.reputation.view`. Support holds every view and `abuse.manage`;
acting against a paying customer is not Support's.

SDK: **1.6**. Additive throughout — `CertificateProvider` and `DnsProvider`
are contracts that did not exist (1.5), and `AdapterArea::Mail` plus
`Capability::ReputationRead` are new enum members (1.6). Nothing a module
already implements changed.

Automation: `abuse-retention`, `certificates`, `zone-health` and `reputation`,
which took the task count from 16 to 20.

## 2. The decisions this phase actually made

**An abuse case is not an incident and not a ticket.** "We broke it", "you
broke it" and "let us talk about it" are three records with three state
vocabularies. An abuse case ends in `actioned`, `no_action` or `rejected`,
never in `resolved`.

**Attribution is at the moment the complaint is about.** `ip_assignments` has
been append-only since Phase C for exactly this, and this phase is the first
thing that reads it that way. One walk, `AttributeReport`, shared by abuse,
DDoS and now reputation — three private copies would eventually disagree
about an assignment released at the very second of the report.

**Core owns the zone checks and no vendor owns them.** Two SPF records is RFC
7208 §3.2, `+all` is worse than no SPF, one nameserver is RFC 1034 §4.1.
Fetching the records is the only part that differs between providers, so that
is the only part that is an adapter. Core does not grade a DMARC policy and
has no views about TTLs: a findings list full of things that are fine is one
an operator stops reading, and then misses the `+all`.

**A certificate's identity is its fingerprint, not its name.** One name is
served by four certificates over a year, and a fleet keyed on the name would
look like the same row clearing and reopening at every renewal.

**Mail is numbers, and that is the whole of it.** A mail queue is a queue and
a bounce rate is a rate, so they arrive through the normalizer that already
exists, land on the Telemetry screen that already exists and are alerted on by
the rules that already exist. §6 is why there is nothing more: the series
belongs in the specialist backend and core keeps the present.

**`mail_queue` and `deferred` were aliases of `QueueDepth` and are not any
more.** They are a mail server's queue, and mixing it with this installation's
own job queue on one metric is two very different outages drawn as one line.

**Nothing delists.** Asking a blocklist to lift a listing is a form with a
human on the other end, usually a captcha, and sometimes a promise about what
has been fixed. A method for it would be a method that lies, so
`ReputationProvider` has one method and the screen carries the list's own link
instead.

**Raise and clear, three times over.** `alerts`, `zone_findings` and
`reputation_listings` are one shape: opened on the first bad observation, kept
while it stays true, cleared rather than deleted. Each needed its own
`cleared_token`, because MariaDB treats nulls in a unique index as distinct
and a key ending in `cleared_at` would allow two open rows while looking as
though it did the work.

## 3. What was found by driving it

Nine things the suite could not see, each of them about what a person reads:

- **`statusTone()` was being handed a tone rather than a status word.** A tone
  is not a status: `IncidentState::Monitoring` drew the unknown mark on a real
  state. `asTone()` is the one place that reads a server-decided tone now.
- **`AppConfirm` never emitted `close`**, so backing out of a dialog left page
  state stale and the dialog could not be reopened without a page reload — on
  about forty screens.
- **A prop named `case`** made every `case.foo` template expression a
  JavaScript parse error.
- **The `security` group was not published** to the browser, so two new
  screens printed their own translation keys.
- **`AlertSeverity` was the wrong scale for a zone finding.** Its members are
  about when somebody is interrupted; a finding is about whether anything is
  broken. `FindingSeverity` exists for that reason.
- **A second `match` on `AlertSubject`.** `isNumeric()` had no arm for the new
  member, and the sweep's own "one rule failing never stops the rest" swallowed
  the error — so the alert simply never appeared, with nothing in the run
  record saying why. A `match` with no default fails loudly at a call site and
  silently inside a sweep.
- **`SystemRoleSeeder` changed a role's permission set and never flushed the
  permission cache.** `CreateRole`, `UpdateRole`, `DeleteRole` and
  `SyncPermissions` all do. This is the seeder an upgrade runs, so a phase that
  gives Support a new permission gave it to a role whose holders went on being
  refused for the cache's fifteen minutes — on a screen the operator can see
  the permission for in the role editor. Found because the new screen answered
  403 to an account that held the permission.
- **No test in the suite could have caught that**, because `phpunit.xml` sets
  `ACCESS_CACHE_TTL` to 0 and the permission cache is therefore disabled
  everywhere. That is the right default for a suite about authorization, and
  it means the one test about the cache has to rebind it — which the new one
  does.
- **Four borrowed column labels**, each now with wording of its own.

## 4. What is still unproven

The same two gaps, unchanged and now larger: **no adapter in this phase has
ever talked to the thing it adapts.** `CertificateProvider`, `DnsProvider` and
`ReputationProvider` are contracts with tests against typed-out answers, which
proves the code and not the integration. A browser proves a screen; only a
real provider proves an adapter.

Nothing in `modules/infracms/` implements any of the three yet. The screens are
honest about it: each empty state says that either nothing is wrong or no
source has been configured, because those two are genuinely different and the
platform cannot tell them apart.
