# 0023 — An issued document is frozen; corrections are credit notes

Status: accepted
Date: 2026-09-26

## Context

An invoice is a tax document in most of the world. It is kept by the
customer, filed by their accountant, and produced years later to explain
what was paid and why.

The obvious implementation computes the invoice from the customer record and
the order: render the customer's current name and address, sum the current
lines. It is less data and it stays consistent automatically.

It is also wrong the first time anything changes. Correcting a customer's
address reissues every invoice they were ever sent, with a different address
from the one their accountant filed. Changing a tax rate changes documents
issued under the old one. A line edited to fix a typo changes what a
customer is recorded as having agreed to pay.

## Decision

**Issuing is the moment a document stops being a working copy.**

At issue, an invoice:

- takes its number from the sequence, allocated inside the same transaction
  that issues it, so a rollback cannot leave a gap;
- copies the bill-to party — name, company, address, country, tax id, email
  — onto itself;
- fixes every line's wording and every amount.

After that, nothing about it changes. A draft is the only editable state,
and `InvoiceStatus::isEditable()` is the one place that says so.

**Corrections are credit notes.** A credit note is its own numbered,
append-only document referencing the invoice. It states what was wrong and
by how much. The invoice is untouched.

The same rule already applies one step earlier: order lines copy the catalog
([ADR 0021](0021-order-lines-copy-the-catalog.md)). Invoice lines copy from
the order line. Each link in the chain copies rather than references, so no
change upstream can rewrite what is downstream.

## Consequences

- The `invoices` table carries bill-to columns that duplicate the customer
  record. That is the cost of a document that stays true.
- "Reprint last March's invoice" is a read. No as-of logic, no versioned
  customer records, no temporal joins — a large amount of machinery this
  decision avoids building.
- A typo on an issued invoice cannot be fixed in place. That is correct: an
  operator issues a credit note and a new invoice, which is what their
  accountant expects anyway.
- Credit notes need their own sequence and their own permission, because
  issuing one asserts the business owes money back.
