# 0019 — Prices are a matrix, and absence means not sold

Status: accepted
Date: 2026-09-24

## Context

A hosting product is not sold at "a price". It is sold monthly, annually and
sometimes triennially, in every currency the operator trades in, with an
optional one-off setup fee on each combination. Configurable options and
addons multiply the same structure again.

Two designs were considered:

1. One price per product, converted into other currencies at the current
   exchange rate and discounted per cycle by a percentage.
2. A matrix: one row per (item, billing cycle, currency), entered by the
   operator.

The first is less data to enter. It is also wrong in the ways that matter: a
converted price moves between the listing and the cart when a rate updates,
a "12 months for the price of 10" discount cannot be expressed as a clean
number, and psychological pricing (9.99 EUR, 12.99 USD, 349 TRY) is
impossible because each currency is derived rather than chosen.

## Decision

Prices are stored as a matrix and entered by hand.

- `product_prices`, `option_prices` and `addon_prices` are keyed by
  (owner, `billing_cycle`, `currency_code`), enforced by a unique index.
- Each row carries `recurring_minor` and `setup_minor` as `bigint`, with the
  currency in its own column. Money is read and written through a value
  object; see [ADR 0014](0014-money-representation.md).
- **A missing row means the item is not sold on that cycle in that
  currency.** Zero means free. The two are different statements and the UI
  keeps them apart: a tick decides whether the cell exists, and the amount
  fields decide what it costs.
- Exchange rates are never applied to a sale price. They exist for reporting
  and for the snapshot an invoice takes in Phase 4.
- Option prices are signed. "No control panel" is priced as a reduction from
  the product price, which is how a cheaper choice is expressed without
  inventing a second base price.

The matrix is saved whole, in one use case, with its own permission
(`catalog.pricing.manage`) and its own audit action
(`catalog.pricing.updated`). An operator editing a grid is making one
decision, and half of it landing is worse than none of it.

## Consequences

- An operator entering a product in five currencies on four cycles types
  twenty cells. The admin screen is built around that being the common case:
  one tab per currency, one row per cycle, and cells that carry their
  previous value when re-ticked.
- Adding a currency does not price anything. Products become sellable in it
  only when someone enters the prices, which is the honest behaviour — the
  alternative silently publishes machine-converted numbers.
- "Why did this customer pay that?" is answerable from the audit trail: the
  pricing action records the before and after of the whole grid, keyed the
  way the grid reads.
- Phase 4 stores the price on the order line rather than referencing the
  matrix, so later edits never rewrite history.
