# 0025 — Documents are numbered in the seller's name

Status: accepted
Date: 2026-09-27

## Context

A customer in this platform is an organization of its own
([ADR 0002](0002-organization-ownership.md)): `CreateCustomer` writes an
organization and a commercial profile together, and everything the customer
owns hangs off that organization. It is what makes the ownership boundary
work.

Order and invoice numbers were allocated against the organization that owns
the document — which is the customer's. Every customer therefore got their
own `ORD-000001`, and the unique index, scoped to that same organization,
was satisfied by numbers that collided with every other customer's. Two
customers held an invoice called `INV-000001`; a lookup by number returned
whichever row the database reached first.

It was found by placing two orders through the storefront and reading the
result.

## Decision

**A document number belongs to the seller, not to the buyer.**

`AllocateNumber` resolves the seller from the organization it is handed:
the nearest ancestor that is not a customer — the reseller for a reseller's
customer, the provider for a direct one. An organization that is not a
customer sells in its own name.

The resolution happens **in the allocator**, not at the three call sites. A
rule that has to be remembered at every call site is a rule that will
eventually be forgotten, and the symptom is a duplicate number in somebody's
accounts.

**A number is unique across the installation.** The unique index moves from
`(organization_id, number)` to the number alone, on orders, invoices and
credit notes. A collision is refused by the database rather than found later
by a customer holding two documents with the same name.

## Consequences

- The document row still belongs to the customer's organization. Ownership
  and numbering are different questions and are now answered separately.
- Allocating a number writes a sequence row owned by the seller from inside
  a customer's boundary. That is a deliberate crossing, confined to the
  allocator, and the only one.
- When Phase 11 gives resellers their own billing, two resellers both
  configured with `INV-` will collide — and the database will say so at the
  moment of issue rather than silently. That is the correct failure: the fix
  is a per-seller prefix, which the sequence row already carries a column
  for.
- The migration that introduces this renumbers documents that already
  collide, keeping the earliest of each group because it is the one most
  likely to have been sent to somebody, and advances each seller's sequence
  past everything already issued.
