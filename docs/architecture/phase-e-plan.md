# Phase E — Security, Abuse, Mail and the Certificate Fleet

Status: planned
Date: 2026-09-27
Previous: `phase-d-result.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §8, §13
Umbrella: `advanced-operations-plan.md` §9 (E)

D was about things going wrong on the platform's own side. E is about things
going wrong on the **customer's** side, and about the one part of hosting where
the operator is answerable to somebody outside: an abuse complaint, a
certificate that lapses, a mail server that starts sending spam.

---

## 1. What ships

1. **Abuse cases** (§13) — a report, who it was about, what was done, and the
   evidence, with a retention clock.
2. **The correlation chain** — Report → IP → Domain → Account → Service →
   Customer → Server, answered from rows this platform already has.
3. **Guarded actions** — suspend, stop outbound mail, force a credential
   reset, contact the customer.
4. **Evidence preservation** — references under a retention policy, never a
   log store.
5. **Certificate fleet** (§8) — expiry, issuer, chain and hostname coverage,
   mapped to domains, services and customers, alerting through Phase D.
6. **Zone health** (§8) — MX, SPF, DMARC, DKIM, NS and DNSSEC questions asked
   of records, with the adapters that fetch them as modules.
7. **Mail operations and reputation** (§13) — queue depth, deferred, rejected,
   authentication failures, bounce rate, RBL listings, as telemetry through
   the normalizer that already exists.

## 2. The decisions

### An abuse case is not an incident and not a ticket

The third record in this family, and the distinction is the same shape as D's:

- An **incident** is the platform's fault. Somebody on the operator's side is
  fixing infrastructure, and the customer is owed an explanation and possibly
  a credit.
- An **abuse case** is the customer's fault, or their compromised account's.
  Somebody outside is owed a reply, the customer is owed a warning, and the
  platform may have to act against its own paying customer.
- A **ticket** is a conversation. A case may open one; it does not become one.

Merging the first two would put "we broke it" and "you broke it" in one list
with one status vocabulary, and the states are not the same: an abuse case
ends in `actioned`, `no_action` or `rejected`, never in `resolved`.

### Attribution is at the time of the report, not now

`ip_assignments` is append-only for exactly this (§5, Phase C). A complaint
about an address last Tuesday must be attributed to whoever held it last
Tuesday — attributing it to the current holder is how an innocent customer is
suspended for somebody else's spam, and it is the single worst mistake this
family can make.

Where the report names a domain rather than an address, the chain runs through
`domains` to the customer. Where it names neither, the case exists with no
subject and says so: a report this platform cannot attribute is still a report
somebody has to answer.

### Core stores references, never a log store

Evidence is where a privacy mistake becomes a legal one. A complaint contains
a third party's data — headers, addresses, sometimes the body of a message —
and hosting it forever in a table nobody sized is the wrong shape twice over.

So: an evidence row carries a **kind**, a **reference** (a URL, a message id,
a log excerpt bounded in size, a hash), the moment it was captured, and a
retention deadline. Core never fetches or stores a mail body, a full log or a
disk image. `platform.abuse.retention_days` is a setting with a dull default,
and a sweep deletes what has passed it — the first thing in this product whose
job is to **forget**.

### A guarded action is a decision recorded, then an action taken

Suspension already exists (`TransitionService`), and a case that suspends
calls it — it does not write `suspended` itself. "Stop outbound mail" has no
seam yet, and inventing a provisioning capability nobody implements would be
the `Manual*` mistake in reverse. So an action is:

1. recorded on the case with who decided it and why, then
2. executed where a seam exists, and
3. left `manual` where one does not, which is a real outcome and appears on
   the screen as work somebody still has to do.

This is ADR 0032's shape (an operation is visible before it finishes) applied
to a decision about a customer.

### A certificate is discovered, never issued here

Core does not run ACME. It reads what is deployed — from a panel adapter, a
DNS adapter, or by looking at what a host presents — and answers the questions
an operator has: what expires in the next fortnight, what is served by the
wrong hostname, what chain is incomplete, whose customer is affected.

Expiry is then an `AlertSubject`, so the operator writes the threshold they
want rather than being told when to care. That is D's decision applied here
and the reason this phase is cheap: the alerting already exists.

### Zone health is a question core may ask, and an answer a module fetches

"Does this domain have an SPF record that ends in `-all`" is a question with
one right answer and no vendor in it. Core owns the checks. Fetching the
records is a `DnsProvider` implementation and belongs in a module —
`integration-modules-plan.md` already lists DNS as blocked on this contract,
and this unblocks it.

### Mail and WAF telemetry stays external

§6 of the umbrella plan, unchanged: queue depth and bounce rate are metrics
through the normalizer, and the specialist backend keeps the series. Core
keeps the present and alerts on it.

## 3. Schema (core)

- `abuse_cases` — organization, reference, kind, state, severity, source,
  reported_at, subject (ip/domain/account, nullable), customer, service,
  summary, closed_at, closed_by.
- `abuse_case_events` — the timeline: case, actor, kind, body, written_at.
  Append-only, like an incident's.
- `abuse_evidence` — case, kind, reference, captured_at, retain_until.
- `abuse_actions` — case, action, state (`pending`/`done`/`manual`/`failed`),
  decided_by, reason, operation (nullable), performed_at.
- `certificates` — organization, node (nullable), domain (nullable), service
  (nullable), common name, SANs, issuer, serial, not_before, not_after,
  chain_ok, discovered_at, source.
- `zone_findings` — organization, domain, check, severity, detail,
  first_seen_at, last_seen_at, cleared_at.

Every one carries `organization_id` and `BelongsToOrganization`.

## 4. Authorization

`security.abuse.view`, `security.abuse.manage` (opening, writing the
timeline), `security.abuse.act` (the guarded actions — high risk, it suspends
a paying customer), `security.certificates.view`, `security.dns.view`.

Support holds the two views and `abuse.manage`: the person a complaint lands
on is the person who should be able to record it. `abuse.act` is not Support's
for the same reason `credits.issue` is not — suspending a customer is a
commercial decision.

## 5. Sequence

Abuse cases first, because the correlation chain is what makes everything else
in this phase worth having and it needs nothing new. Then certificates, which
is a table and an alert subject. Then zone health and the DNS contract. Mail
and reputation last, because they are adapter families and the pattern is
Phase B's, already proven.

## 6. Not in this phase

- **Running ACME.** Issuing a certificate is a provisioning module's job, and
  core reading what is deployed is a different and smaller promise.
- **A spam filter.** Rspamd and ClamAV are integrations; core correlates what
  they report and does not classify anything itself.
- **Automatic suspension.** Every guarded action is a person pressing a
  button. A platform that suspended a customer because a robot said so would
  need to be right every time, and no abuse signal is.

## 7. The standing limitation

The same as every phase since B, and worse here: an abuse report in the real
world arrives as an email to `abuse@`, in a format nobody agreed, from a
sender who may be wrong. Core's ingestion is a form an operator fills in and
an API endpoint a module can post to. **No mailbox is read by this code**, and
the first real complaint is still somebody's first real complaint.
