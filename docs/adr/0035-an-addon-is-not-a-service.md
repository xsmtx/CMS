# 0035 — An addon is not a service, and not just an order line

**Status:** accepted
**Date:** 2026-09-23
**Supersedes:** nothing. Extends [0021](0021-order-lines-copy-the-catalog.md),
[0026](0026-provisioning-is-idempotent-and-failure-is-a-state.md) and
[0031](0031-a-run-is-a-record.md).

## Context

Until now an addon existed in exactly one place after checkout: an order
line with `kind = addon` hanging off the product line it was bought with.
`CreateServicesForOrder` said so out loud — "addon lines do not become
services of their own" — and that was right as far as it went.

It stopped going far enough the moment anybody asked a second question
about one. An order line records what was bought **once**. It has no
renewal date, so the renewal sweep cannot see it; no status, so dunning
cannot suspend it; no term, so an operator cannot answer "when does the
extra backup space renew" without reading the parent service's date and
hoping they match. A customer paying five euros a month for extra disk was
being invoiced for it exactly once, at checkout, forever.

The two obvious answers are both wrong.

**Make each addon a service.** It would renew and suspend correctly, and it
would also give the customer two rows in the client area for one thing they
bought, a second provisioning target the module knows nothing about, and a
second thing to terminate when they cancel. An addon has no account of its
own at the provider; it is a property of one.

**Leave it as an order line and read the parent's dates.** This is what
makes the admin screen the user asked for impossible to build honestly: a
list of order lines dressed as billable rows, with a "next due date" column
computed from something else and a "status" column with nothing behind it.

## Decision

An addon a customer is paying for is a **`service_addons` row**: its own
price, its own billing cycle, its own renewal date and its own status,
hanging off a service rather than standing beside one.

1. **It is a copy**, exactly as a service and an order line are (ADR 0021).
   The name, the cycle, the quantity and every amount are written when the
   order is paid. Nothing reads a price back through the catalog, so an
   addon repriced next March does not change what this customer pays, and
   retiring the catalog entry does not take the paying row away —
   `addon_id` is nullable for that reason.

2. **Creation is idempotent**, through a unique index on `order_item_id`,
   exactly as services are (ADR 0026). An order paid twice — a webhook
   replayed, an operator recording a transfer a webhook then confirms —
   finds the row that exists. Addons are written on every fulfilment run,
   not only the one that created the service, so a run that crashed between
   the two finishes the job on the next attempt.

3. **The status has its own, smaller enum.** `AddonStatus` has no
   `provisioning` and no `failed`, because an addon is not provisioned on
   its own in this platform: the parent service's module puts the whole
   account together, extra disk and all. An enum member nothing can ever
   set is a screen filter that always returns nothing.

4. **The addons follow their service.** `TransitionService` is the one
   place that moves them, for the same reason it is the one place that
   moves a service: two is one too many. Suspending a service suspends its
   addons; resuming brings them back; terminating takes them with it. Two
   exceptions, both about not undoing a customer's own decision — a
   terminated addon stays terminated, and a cancellation the customer asked
   for survives a suspension.

5. **The renewal sweep asks about addons the way it asks about services**
   (ADR 0031): which rows are due and not yet invoiced through that date.
   They land on the same invoice as everything else renewing for that
   customer in that currency, because that is what a bank transfer can pay.
   A cancelled addon is left out: it is still running until the term ends,
   and invoicing it would charge for a term the customer has said they do
   not want.

6. **`AdvanceRenewalDates` moves the addon forward when the invoice is
   paid**, from the line's `period_end`, exactly as it does for a service.
   Payment is the event, not issuing.

7. **The screen is read-only.** An addon is sold with a product — at
   checkout, or by an operator building an order. A screen that let
   somebody conjure a billable row out of nothing would produce a charge
   with no order behind it, and an invoice nobody can explain.

## Consequences

An addon is now four things instead of one: a catalog entry, an order line,
a `service_addons` row, and an invoice line. That is the same shape a
product already has, and the same reasons hold — each one is a copy of the
one before it, frozen at the moment it mattered.

Installations that were running before this migration have order lines with
no `service_addons` row behind them. Nothing backfills them, deliberately:
inventing a renewal date for a term nobody agreed to would start charging
customers for something they have not been charged for, and that is an
operator's decision rather than a migration's. The rows exist from the next
order onwards; anything before that is added by hand, with an order behind
it.

The client area does not show addons separately yet. They are part of what
the service is, and the service screen is where a customer looks — but the
row now exists to show, which it did not before.
