# Phase 15 — Import / Migration Plan

Status: complete — see `phase-15-result.md`
Date: 2026-09-24
Previous: `phase-14-result.md`
Handoff: §13 (Import / Migration Framework), §22 Phase 15

---

## 1. The pipeline, and where the difficulty actually is

The handoff's pipeline is

```text
Connect/Upload → Analyze → Map → Dry Run → Validate → Import → Reconcile → Report
```

and the requirements are resumable jobs, deterministic external-ID mapping,
duplicate protection, a dry run, detailed errors and **no silent data loss**.

The difficulty is not reading a legacy schema. It is that an import looks like
a write path and is not one. Everything else in this platform routes business
changes through a use case so that the rules hold and the events fire. An
import must do the opposite, and that is the decision this phase rests on.

## 2. The decisions

**An import writes rows and dispatches nothing.** It is a copy of history, not
a set of new business events.

`IssueInvoice` would allocate a fresh number and lose the one the customer has
in their filing cabinet. `TransitionOrder` announcing `OrderPaid` for two years
of historical orders would provision two years of services, charge nobody, and
email every customer the platform has just acquired. `RecordPayment` would
recalculate a balance that is already settled.

So importers write through Eloquent with `forceFill`, deliberately, and the
reason is written beside every one of them. The tests assert that no
notification and no queued job leaves an import.

**Deterministic mapping is a table, not a convention.** `import_mappings` holds
`(organization_id, source, domain, external_id) → (target_type, target_id)`
with a unique index. Three things fall out of it for free:

- **duplicate protection** — a row already mapped is skipped, not written twice;
- **resumability** — a run that died halfway resumes by skipping what is
  mapped;
- **reconciliation** — "which of my WHMCS clients came across" is a join, not a
  guess.

A convention such as "match on email" would silently merge two customers who
share an address, which is the commonest way an import loses data without
anybody noticing.

**A dry run is the same code path.** One flag, checked in one place: the writer.
A dry run that took a different path would be a dry run that proves nothing,
which is the failure mode every dry run has.

**One row failing never stops the run**, and every failure is recorded with the
external id and a sanitised reason. The same rule the automation sweeps follow
(ADR 0031): an import that stopped on row 4,000 of 12,000 leaves an operator
with no idea what came across.

**No silent data loss means a row is never partially imported.** Each row is
its own transaction. A customer whose contact fails is a customer with no
contact and a recorded failure, not a half-written customer.

**The legacy connection is read-only, and its credentials are never stored by
this platform.** It is a Laravel database connection the operator configures,
which means it lives in their environment file next to their own database
credentials — where such things belong — and the importer never issues anything
but `select`.

**Money arrives as a decimal string and becomes integer minor units at the
boundary.** WHMCS stores `decimal(16,2)`. The conversion happens once, in the
mapper, through `Money::ofDecimal()` — and a row whose currency this
installation does not know is a recorded failure rather than a guess.

## 3. What ships

- `ImportDomain`, `ImportOutcome`, `ImportRecord`, `ImportSource` in `Domain`.
- `import_runs`, `import_items`, `import_mappings`.
- `AnalyzeImport`, `RunImport`, `ImportWriter`, and one mapper per domain.
- `WhmcsImportSource` — the first adapter, reading a WHMCS-shaped MySQL schema.
- A queued job so a twelve-thousand-row import is not an HTTP request, wrapped
  so the run row exists before the job is handed over (ADR 0032).
- The admin screen: analyse, dry run, import, and the report with every failure
  on it.

## 4. Not in this phase

- **Uploading a database dump.** The handoff says "Connect/Upload"; connecting
  to a read-only replica is the safe half and the one an operator running a
  migration actually has. Accepting a 4GB `.sql` upload, storing it and
  importing it is a file-handling problem with its own security surface
  (handoff §20), and Phase 17 is where upload rules are settled.
- **Adapters for anything but WHMCS.** The framework takes a contract; the
  second adapter is somebody's afternoon once there is a customer asking.
- **Provisioning the imported services.** An imported service is a copy of one
  that already exists on a server. Provisioning it would create a second.

## 5. Honesty about the adapter

`WhmcsImportSource` has never read a real WHMCS database. Its queries are
written against the documented and widely known table shapes, every column it
reads is named in one place, and a missing column fails the analyse step with
the column's name rather than halfway through an import. That is the same
position the Stripe, cPanel and Namecheap adapters are in, and it is stated in
the class docblock rather than left to be discovered.
