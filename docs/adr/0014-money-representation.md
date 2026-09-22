# 0014 — Money as integer minor units

Status: accepted
Date: 2026-09-22

## Context

The platform issues invoices, takes payments, applies proportional
promotions, computes tax and prorates upgrades, in multiple currencies. IEEE
754 doubles cannot represent 0.10 exactly; accumulating such values across
invoice lines produces totals that are off by a cent, and a financial system
that is off by a cent is wrong.

## Decision

Money is always an integer count of minor units paired with an ISO 4217
currency code. `float` never appears in a monetary path — not in a column,
not in a DTO, not in a JSON payload, not in a calculation.

- Columns are `bigint` plus a `char(3)` currency.
- Currencies with other exponents (JPY has none, KWD has three) are handled
  by the currency's own exponent, never by assuming two decimals.
- Arithmetic goes through a `Money` value object introduced with the catalog
  in Phase 2, which refuses to operate across currencies.
- Allocation of a discount or tax across lines uses a largest-remainder
  distribution so the parts always sum exactly to the whole.
- Invoices snapshot the exchange rate used, so a historical document never
  changes value because a rate moved.

## Consequences

- Presentation must divide by the exponent at the edge, and only there.
- This ADR is recorded in Phase 0, before any monetary code exists, so that
  no later phase has to argue the case or migrate a float column.
