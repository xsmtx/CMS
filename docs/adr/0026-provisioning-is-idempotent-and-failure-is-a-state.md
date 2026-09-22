# 0026 — Provisioning is idempotent, and failure is a state

Status: accepted
Date: 2026-09-27

## Context

Everything the platform has done so far can be undone by rolling back a
transaction. An account created on somebody else's control panel cannot.

That asymmetry is the whole problem. A worker restarts mid-call, a webhook
is redelivered, an operator refreshes a page that seemed stuck — and each of
those, handled naively, creates a second hosting account that nobody is
billing for and nobody knows exists. The customer finds it eventually; so
does the auditor.

## Decision

**A service is a copy.** The product name, billing cycle, price, package and
chosen options are written onto the service when it is created, the same
rule as an order line
([ADR 0021](0021-order-lines-copy-the-catalog.md)) one step further along. A
product repriced next March does not change what an existing service costs,
and a renamed option does not rewrite the disk quota somebody is running on.

**The external id is written the moment it arrives**, before the status
changes and before the event is recorded. An id that was not stored is an
account nobody can find again.

**Results are three-valued.** `succeeded`, `failed`, and **`already_done`** —
the third is what makes retrying safe. "This account already exists" is the
state the caller was trying to reach, and an adapter that reports it as an
error turns every retried job into a permanent failure. Both shipped
adapters map their provider's phrasing onto it, and the cPanel adapter
derives its username deterministically from the domain so that a retry asks
for the same account rather than a differently-named second one.

**Failure is a state, not an exception.** A run that cannot succeed leaves
the service in `failed` with a sanitised reason, in a queue an operator
works through. It does not throw into a log nobody reads, and — because the
job's `failed()` hook says so after the last try — it does not sit in
`provisioning` forever, which is the state that makes an operator stop
trusting the screen.

**Adapters never touch the database.** They take a value object and return
one. There is no path by which a provider integration corrupts the record,
reaches a customer through a relation, or writes a credential; credentials
come back in the result and are encrypted at rest by the caller, once, where
the key lives.

**The remote call happens outside any transaction**, and one job runs per
service at a time (`ShouldBeUnique` on the service id).

**Placement belongs to the server group**, not the product, so an operator
rebalancing a fleet edits one row. Three rules hold whatever strategy is
chosen: a node not `active` is never picked, a node at capacity is never
picked, and if nothing can be picked the service fails loudly. A service on
a node that cannot hold it is a support ticket tomorrow; one that failed to
place is an operator's queue today.

## Consequences

- `services.order_item_id` is unique. One purchase, one service, however
  many times the order reaches `paid`.
- A service that never existed remotely is terminated without a remote call.
  Asking a provider to delete something it was never told about produces an
  error that means nothing.
- Suspended services count against a node's capacity; terminated ones do
  not. The account is still there in the first case.
- Retrying is the normal response to a failure, so `failed` transitions back
  into `provisioning` rather than being terminal.
- An operator cannot declare a service `active` by hand. Saying a service
  works does not make an account exist; that is what running the operation
  is for.
