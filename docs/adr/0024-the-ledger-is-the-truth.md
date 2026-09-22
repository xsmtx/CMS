# 0024 — The ledger is the truth; a redirect is not

Status: accepted
Date: 2026-09-26

## Context

Two questions decide how a billing system behaves when things go wrong:

1. **What does an invoice's paid amount mean?** If it is a counter that gets
   incremented, a webhook delivered twice pays the invoice twice, and a bug
   in one code path leaves a number nobody can derive from anything.
2. **When is a payment real?** A customer returns from a gateway to
   `?success=true`. Believing that is how a platform gives away service for
   payments that were never made — the URL is supplied by a browser and can
   be typed by hand.

## Decision

**Every movement of money is an append-only ledger row.** Transactions
carry a kind (`payment`, `refund`, `credit_added`, `credit_applied`,
`credit_note`, `adjustment`) and a **positive** amount; the kind decides
which way it moves, so a refund cannot be recorded as income by a call site
that got a sign wrong.

An invoice's `paid_minor` and a customer's credit balance are **caches** of
those rows. `RecordPayment::settle()` rebuilds the paid amount from the
ledger every time anything changes and transitions the invoice from the
result. If the column and the rows ever disagree, the rows win.

**Nothing is paid without a verified event.** The rules, each with a test:

- A redirect back to the site marks nothing. The return page says the
  payment is being checked and shows only what has already been recorded.
- A gateway's answer to a server-to-server call we made *is* believed —
  that is not a browser talking.
- A webhook's signature is verified over the exact bytes received, before
  the payload is parsed, with a timestamp tolerance so an old payload cannot
  be replayed.
- Events deduplicate on the provider's own event id, enforced by a unique
  index rather than a check-then-write, because two deliveries can arrive at
  once.
- Outbound calls carry an idempotency key, and the payment row is written
  *before* the call so a lost answer still has something to attach to.

**One path settles an invoice.** A card confirmed by webhook and a bank
transfer typed in by an operator both go through `RecordPayment`, produce
the same ledger rows, and move the order the same way. There is no second
implementation to drift.

## Consequences

- Overpayment is not an error: the excess is a `credit_added` row, and
  "what has this invoice been paid" stays a straight sum.
- Recalculating on every change costs a query over one invoice's rows. At
  the point where an invoice has thousands of them, something else has
  already gone wrong.
- A refund made directly at the gateway is recorded but not acted on.
  Inventing a refund row from a payload we did not initiate would put money
  movements in the books that nobody authorised here; reconciling it is an
  operator's decision.
- The ledger is what a dispute is argued from. Because it is append-only and
  positive, reading it does not require knowing which code path wrote it.
