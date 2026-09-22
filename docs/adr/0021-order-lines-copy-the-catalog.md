# 0021 — Order lines copy the catalog, they do not reference it

Status: accepted
Date: 2026-09-25

## Context

An order line has to say what was bought, on what terms, for how much. The
obvious implementation references the catalog: store `product_id`,
`option_id` and `billing_cycle`, and read the name and the price back when
the order is displayed or invoiced.

That design fails in three ordinary situations:

- An operator raises a price. Every past order silently says the customer
  agreed to the new one.
- An operator deletes a retired option. An old order becomes unreadable, or
  worse, renders as a blank where a choice used to be.
- An invoice issued in March is reprinted in April with different numbers.

None of these are edge cases. They are Tuesday.

## Decision

An order line stores a **copy** of everything that describes the sale: the
product's name, the option group names and labels, the billing cycle, the
currency, and every amount in minor units. The catalog ids are stored
alongside, for reporting, and are nulled rather than cascaded when the
catalog row is deleted — losing the product must not lose the record of its
sales.

The same rule extends forward. Phase 4's invoice lines copy from the order
line rather than the catalog; Phase 6's services carry their own copy of
what they were provisioned as.

A cart is the exception and is deliberately the other way round: its lines
reference the catalog and are priced on every read, so a cart left open
overnight shows this morning's price. The copy happens once, at placement.

## Consequences

- The tables are wider, and the same string exists in several rows. That is
  the cost of a record that stays true.
- "What did this customer agree to" is answerable by reading one row. No
  join, no as-of logic, no reconstruction.
- Reporting that groups by product joins on the retained id and accepts that
  deleted products fall out of the grouping while their revenue stays in the
  totals.
- Changing a product cannot corrupt history, which means catalog editing
  needs no as-of versioning to be safe. That is a large amount of machinery
  this decision avoids building.
