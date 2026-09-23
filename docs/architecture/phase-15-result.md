# Phase 15 — Import / Migration Result

Status: complete
Date: 2026-09-24
Plan: `phase-15-plan.md`
Handoff: §13 (Import / Migration Framework), §22 Phase 15
ADR: `0042-an-import-writes-rows-and-dispatches-nothing.md`

---

## 1. What shipped

The framework and the first adapter.

- `ImportDomain` (eight, in dependency order), `ImportMode`, `ImportOutcome`,
  `ImportStatus`, `ImportRecord`, and the `ImportSource` contract.
- `import_runs`, `import_mappings`, `import_items`.
- `ImportWriter` — the one place anything is written, and the one place the dry
  run flag is read.
- `LegacyValues` — the half-dozen conversions with traps in them, in one place.
- Eight mappers, one per domain, each stating which use case it is not calling.
- `RunImport` — the pipeline, with the run recorded item by item.
- `StartImport` + `RunImportJob` on an `imports` queue with its own Horizon
  supervisor.
- `WhmcsImportSource` — cursor reads, and `check()` that verifies every column
  before anything is written.
- The admin screen: what is connected, what is there, dry run, live run, and the
  report.

## 2. The decision this phase rests on

ADR 0042: **an import writes rows and dispatches nothing.** It is a copy of
history, not a set of new business events. The ADR has the full reasoning; the
short version is that the alternative *looks* correct — a migration that ran two
years of orders through the real fulfilment path would produce a perfectly
consistent database, a few thousand duplicate hosting accounts and a support
queue full of confused customers.

The test that holds it runs a customer, an invoice and a transaction through in
one go with `Queue::fake()` and `Notification::fake()` and asserts nothing was
pushed and nothing was sent.

## 3. The other decisions

**Deterministic mapping is a table, not a convention.** One unique index gives
duplicate protection, resumability and reconciliation. Matching on an email
address would silently merge two customers who share one, which is the commonest
way a migration loses data without anybody noticing.

**A dry run is the same code path with one flag**, read in `ImportWriter` and
nowhere else — and it writes no mappings either, or the live run would skip every
row.

**Two fields exist to stop the platform undoing the import.**
`renewal_invoiced_through` is set to the next due date on every imported service
and domain. Without it, the first nightly renewal sweep after a migration
invoices every customer again. That is the single most expensive way to get one
wrong and it is tested.

**Imported records are written into states that assert nothing is in flight.** A
service is never `provisioning` (the account already exists on somebody's
server), a domain is never `registering`, an invoice is never `draft` (a draft is
the only editable state and an imported invoice is a document the customer
already has), and **every ticket is closed** — an imported open ticket arrives
two years past an SLA it never had and sits at the top of the queue on the
morning after the migration looking like an emergency.

**Hard parents only.** Exactly one: a record belongs to a customer or to nobody.
Products are not a hard parent of services, because `ServiceMapper` writes a null
`product_id` deliberately — a service with the wrong product is worse than one
with none — and invoices are not a hard parent of transactions, because a legacy
system records credit top-ups against no invoice at all. This was found by a test:
the first version listed the soft parents and refused "customers and services
only", which is an entirely ordinary thing to run.

**An unrecognised billing cycle fails the row.** Everywhere else in the importer
an unrecognised legacy value degrades to a sensible default, because losing a
real customer over a word would be worse. Not here: a service imported at the
wrong cycle bills somebody wrongly forever, and guessing "monthly" for an annual
account undercharges by a factor of twelve until the renewal.

**MySQL's zero date is read as no date.** `0000-00-00` is everywhere in a real
legacy database and Carbon parses it into the year zero without complaint — which
becomes a renewal date two thousand years ago and a dunning sweep that tries very
hard. Anything before 1990 is treated the same way: it is not a hosting record.

**Nothing secret is read or written.** `tblclients.password` is not in the column
list at all. Imported contacts get a random password and reach the portal through
the reset flow. `portal_access` is false unless the legacy row says otherwise,
because an import that handed portal access to every address in a contacts table
would be an import that emailed a few thousand people who never had an account.

**A legacy driver's exception message is sanitised before it reaches a row**, via
`SecretRedactor` — those messages carry connection strings, and the row is about
to be read on a screen.

## 4. What did not ship, and why

- **Uploading a database dump.** The handoff says "Connect/Upload"; connecting to
  a read-only replica is the safe half and the one an operator running a
  migration actually has. Accepting a multi-gigabyte `.sql` upload has its own
  security surface (handoff §20) and Phase 17 is where upload rules are settled.
- **Adapters for anything but WHMCS.** The framework takes a contract. The second
  adapter is somebody's afternoon once there is a customer asking.
- **Invoice line items.** A legacy line is free text with an amount; a line here
  copies a product, an option set and a cycle from an order. Writing the legacy
  description into one would produce documents that look right and cannot be
  recalculated. The totals are what the customer owes.
- **Account credit.** A legacy credit history is that system's own running
  arithmetic, and reconstructing it from a partially imported set of transactions
  would produce a balance nobody could unpick. Doing it halfway is worse than not
  doing it, and the ADR says so rather than the code implying otherwise.
- **Ticket replies.** The index comes across; the bodies stay where they are.
- **Provisioning the imported services.** An imported service is a copy of an
  account that already exists. Provisioning it would create a second.

## 5. Honesty about the adapter

`WhmcsImportSource` **has never read a real WHMCS database.** Its queries are
written against the documented and widely known table shapes. Every column it
reads is named in one constant, `check()` verifies all of them against the live
schema before anything is written, and a schema that differs produces one legible
list of missing columns rather than a half-finished import. That is the same
position the Stripe, cPanel and Namecheap adapters are in, and it is stated in
the class docblock as well as here.

What *is* tested is the pipeline, against a fake source that yields rows using
WHMCS's own column names — so a mapper reading the wrong key fails in this
repository rather than on a customer's migration.

## 6. Gates

Pint, Rector, PHPStan level 8, Pest on MariaDB, ESLint, Prettier, vue-tsc,
Vitest, Vite build, `platform:openapi --check` — all green. 1233 Pest tests.
