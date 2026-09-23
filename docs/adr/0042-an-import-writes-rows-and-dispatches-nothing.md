# 0042 — An import writes rows and dispatches nothing

Status: accepted
Date: 2026-09-24

## Context

Everything else in this platform routes a business change through a use case.
That is how the rules hold: `IssueInvoice` allocates a number and freezes the
document, `TransitionOrder` announces `OrderPaid`, `RecordPayment` is the one
path that settles an invoice and rebuilds its paid amount from the ledger.
Twelve phases of decisions rest on nothing bypassing them.

An import has to bypass all of them, and it took writing the first mapper to see
why clearly.

- `IssueInvoice` would allocate a number from **this** installation's sequence.
  The customer has the old number in their filing cabinet and their accountant
  has it in a ledger. An invoice whose number changed during a migration is an
  invoice nobody can reconcile, and the operator finds out when a customer
  telephones about a payment they cannot match.
- `TransitionOrder` announcing `OrderPaid` for two years of historical orders
  would provision two years of services — creating accounts that already exist,
  on servers that already hold them — and email every customer the platform has
  just acquired.
- `RecordPayment` would recompute a paid amount the legacy system has already
  settled, from transactions that may not all have come across, and mark settled
  invoices unpaid.

The alternative to bypassing them is worse in a way that is easy to miss: it
looks correct. A migration that ran every historical order through the real
fulfilment path would produce a perfectly consistent database and a support
queue full of confused customers.

## Decision

**An import writes rows through Eloquent and dispatches nothing.** It is a copy
of history, not a set of new business events.

- Mappers write with `create()` and `forceFill()`, deliberately, and each one
  states in its own docblock which use case it is not calling and why.
- No event is raised, no job is queued, no notification is sent. A test asserts
  it with `Queue::fake()` and `Notification::fake()` across a customer, an
  invoice and a transaction in one run.
- Legacy identifiers, numbers and dates are preserved exactly. The legacy
  invoice number **is** the number.
- Imported records are written into states that assert nothing is in flight: a
  service is never `provisioning`, a domain is never `registering`, a ticket is
  always closed, an invoice is never `draft`.
- Two fields exist to stop the platform's own automation undoing the import.
  `renewal_invoiced_through` is set to the next due date on every imported
  service and domain, because without it the first nightly renewal sweep after
  a migration invoices every customer again — the single most expensive way to
  get one wrong.

**Deterministic mapping is a table.** `import_mappings` holds
`(organization_id, source, domain, external_id) → (target_type, target_id)` with
a unique index, and three requirements fall out of that one index: duplicate
protection, resumability, and the ability to answer "which of my old clients
came across" months later. A convention such as matching on email address would
silently merge two customers who share one, which is the commonest way a
migration loses data without anybody noticing.

**A dry run is the same code path with one flag**, checked in `ImportWriter` and
nowhere else. A dry run that took a different path would be a dry run that
proves nothing: it passes, the live run fails, and the operator has already told
their customers. A dry run also writes no mappings, or the live run would skip
every row.

**One row is one transaction, one row failing never stops the run, and every
row is recorded by name.** No silent data loss means an operator can read the
four hundred rows that did not come across and decide about each; a run that
stored only totals would tell them the number, which is the least useful part.
`completed` means the run finished, not that every row succeeded — the same
distinction ADR 0031 makes for automation.

**Hard parents only.** A domain is refused before anything is written when its
*hard* parent has never been imported, and there is exactly one hard parent: a
record belongs to a customer or it belongs to nobody. Products are not a hard
parent of services and invoices are not a hard parent of transactions, because
both mappers tolerate the absence deliberately — a service with no product still
bills correctly, and a legacy system records credit top-ups against no invoice at
all. Listing the soft parents would refuse "customers and services only", which
is an ordinary thing to run.

## Consequences

- **The import is the one write path in this product that is not covered by the
  use-case rules**, and it is therefore the one that has to be read carefully
  when a rule changes. A phase that adds a required column to invoices must add
  it to `InvoiceMapper` too, and nothing but a test will catch that.
- **Imported products arrive unpriced and hidden.** A legacy products table holds
  a zero-filled price column per cycle; importing them faithfully would create a
  price of zero for every cycle, and zero means *free* here rather than unpriced.
  The screen says so.
- **A customer's account credit is not carried across.** A legacy credit history
  is that system's own running arithmetic, and reconstructing it from a
  partially imported set of transactions would produce a balance nobody could
  unpick. Imported transactions are history a report can read.
- **Imported contacts have no password, not even a hash.** A legacy hash is
  somebody else's algorithm; importing it would either not work or work by this
  platform accepting another system's crypto. Accounts are reached through the
  reset flow, as every account here is.
- **Ticket replies do not come across.** Subjects and dates give an operator the
  index; the bodies stay in the old system, which is honest about what a
  migration of the largest table in a legacy database would cost.
