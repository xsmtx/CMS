# Phase 13 — Reseller Result

Status: complete
Date: 2026-09-24
Plan: `phase-13-plan.md`
Handoff: §2 (Reseller-Ready Ownership Model), §4 (Reseller Area), §22 Phase 13

---

## 1. What shipped

All six slices.

1. **Create and manage a reseller.** `CreateReseller` writes the organization
   and its first staff account in one transaction, under the provider and
   nowhere else. No password is chosen by the creator: the account is reached
   through the reset flow, so there is no secret to email or read aloud.
2. **Product availability.** `reseller_products` rows, and **absence is a
   refusal** — a reseller with no rows sells nothing.
3. **Margins and price overrides.** `ResolveSellingPrice`, asked by the
   storefront, the cart and admin order entry. Pricing **resolves**: an exact
   reseller price wins, then a margin, then the provider's price. One winner,
   never an average.
4. **The account.** `ResellerLedger` plus `reseller_ledger_entries`.
5. **Reseller performance.** `ResellerPerformance`, one row per reseller.
6. **The audit.** `ResellerScreenAuditTest` drives a reseller's Administrator
   at every admin screen.

Slices 1 to 3 had shipped their *rules* before this phase's second half and no
operator surface at all — `CreateReseller` had no route, availability and
margins had no screen. A rule nobody can reach is a rule nobody uses, so
finishing them meant building `/admin/resellers`.

## 2. The decisions worth keeping

**`resellers.administer` is a gate, not a permission — and it cannot be one.**
A reseller's own Administrator holds every staff permission there is, by
design: the seeder gives that role the whole staff scope so a permission added
in a later phase reaches it. A permission called `resellers.manage` would
therefore land on a reseller's own administrator and let them set their own
margins and write their own balance. The gate asks which organization somebody
belongs to instead — only an organization whose `permittedChildTypes()`
includes `Reseller` may administer them — with `organizations.manage` still
required on top. It is the same shape as `isSuperAdmin`: who you are rather
than what you hold.

This is the third place in the platform where a capability could not be
expressed as a permission, and the reason is the same each time.

**A positive balance is what the reseller holds.** Negative means they owe the
provider. Written down in the service, the screen and the language file,
because it is exactly the sort of convention that gets read backwards by the
third thing to use it — and a reseller shown "−400.00 credit" is a reseller on
the telephone.

**`Adjustment` became `Withdrawal`.** The kind decides direction (ADR 0024),
so a kind cannot be a word that does not: "adjustment" can go either way.
`Credit` is the increase somebody grants with a reason and `Withdrawal` is the
decrease, and neither needs a sign at the call site.

**The running balance is written under a lock.** Each row carries the balance
after it, so a statement is readable without summing the table and each row
records what the balance was at the time. That makes write order matter, so
`record()` locks the account's newest row inside the transaction — two
payments recorded at once would otherwise both build on the same previous
balance.

**Balances and every reported figure are per currency and never summed.**
There is no rate anywhere in this platform, and a total across currencies
would be a number nobody could reconcile.

**Attribution is a join, because there is one level of resale.** A reseller
owns customers and nothing else, so the organization that owns an order *is* a
customer and its `parent_id` is the seller. `ResellerPerformance` groups on
that. Sub-resellers would have made this a recursive walk of `path` and the
report a different shape — which is the clearest argument yet for the decision
in slice 1.

**Half of "reseller reports" already existed.** Every query in this panel is
narrowed by the boundary, so a reseller signing in already sees their own
customers, their own recurring revenue and their own overdue invoices on the
dashboard. Only the provider's roll-up needed building. Writing a second,
reseller-flavoured dashboard would have been a second thing to keep in step.

**An empty margin is not a margin of zero.** One means "the provider's
price", the other means somebody typed zero, and a screen showing 0% where
nothing was set would be lying about a decision nobody took. The same
distinction the column was created with, now held by a test.

**Turning a product off keeps the margin.** The row stays with `is_enabled`
false, which is what lets a provider suspend a product for a month without
losing the number somebody agreed on the telephone.

## 3. The audit found nothing, and that is the result

Slice 6 walks the router — every `GET` under `/admin` that needs no
parameters, guest routes excluded by their middleware rather than by name —
and drives all of them as a reseller's Administrator. Three outcomes are
acceptable (200, 403, 404) and a 500 would be a screen that assumed the
provider. Then it re-requests every screen that answered 200 and greps the
body for three records the provider owns: a product, a server hostname and a
customer name.

**No screen leaked and no screen broke.** That is not a null result: it is the
boundary built in Phase 0 holding across thirteen phases of screens written by
somebody who was not thinking about resellers at the time. The record-level
half of the audit — a real provider id in a reseller's hands, on customers,
invoices, services and a product's addons — answers 404 throughout, never 403,
because a 403 confirms the record exists.

Two guards keep the audit honest. One asserts the route filter still matches
more than twenty-five screens, so the audit cannot pass by finding none. The
other asserts the leak check actually examined more than ten bodies, so it
cannot pass by checking nothing. Both are the failure mode this kind of test
has.

**A screen added in a later phase joins this test the day it is routed.**

## 4. What was deliberately not built

- **Invoicing a reseller for what its customers bought.** The balance and the
  movements exist; deciding *when* the provider bills a reseller — per order,
  monthly, on a threshold — is a billing model, and guessing it would mean
  rewriting it. Recording a `charge` by hand works today.
- **Sub-resellers.** One level of resale, and the report above is why.
- **A reseller-specific theme surface.** `CurrentBrand` already resolves the
  seller's brand per surface (ADR 0036), which Phase 11 finished.
- **Parameterised routes in the automated audit.** A made-up id only ever
  proves that a 404 happens. The record-level probes use real ids belonging to
  the wrong organization, which is the assertion that means something.

## 5. Gates

Pint, Rector, PHPStan level 8, Pest on MariaDB, ESLint, Prettier, vue-tsc,
Vitest, Vite build — all green. 1168 Pest tests.
