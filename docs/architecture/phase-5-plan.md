# Phase 5 — Client Area Plan

Status: approved for implementation
Date: 2026-09-27
Scope: V2 roadmap Phase 5 — the customer's side of the platform. Dashboard,
billing, orders, account and the developer section. Services, domains and
support are the subjects of Phases 6, 7 and 8; this phase builds the shell
they land in and nothing that pretends they exist.

---

## 1. Starting point

Four phases have built a platform a customer cannot see. They can buy
(Phase 3), and since the last fix they can pay the invoice their checkout
raised — but only by holding on to the tab it was raised in. Everything else
about their account is reachable only by a staff member.

What exists to build on:

- A `client` guard, a `ClientLayout` shell, profile and contacts screens
  (Phase 1), and security, 2FA and sessions at `/security`, shared with
  staff.
- A `portal.*` permission family in the `customer` scope, with
  `account-owner` holding all of it and `portal-member` a narrow subset
  (Phase 1).
- Invoices, payments, the ledger, credits, credit notes and two gateways
  (Phase 4), all of it written from the operator's side.
- Orders with a full state machine and history (Phase 3), with no customer
  ever having seen one.

## 2. The central decision: the client area reads what the admin reads

There is no customer-facing copy of anything. A client screen resolves the
**same models through the same application services** as the admin screen,
narrowed by one extra condition: the contact's own customer.

That is not a shortcut, it is the rule that keeps the two sides honest. A
customer who is told they owe 149.90 and an operator who is told they owe
149.90 are reading the same row, so the two numbers cannot drift. Where a
customer needs less — an invoice without its internal notes, a ledger
without an operator's adjustment reason — the **presenter** drops fields;
the query does not change.

This phase therefore adds no billing logic whatsoever. If a client screen
seems to need a new use case, that is a sign the admin side was missing it
too.

## 3. Authorization is three questions here, not two

The usual pair — organization boundary, then permission — leaves a gap this
phase would fall straight into. A contact is inside their customer's
organization and holds `portal.billing.view`; that says nothing about
*which* invoice they are asking for.

Every client screen therefore asks:

1. **Boundary** — the organization scope, as everywhere.
2. **Ownership** — `customer_id` equals the contact's own. Not a filter
   applied by the caller: a single `ForCurrentCustomer` scope that the
   client controllers resolve their models through, so forgetting it is not
   possible by omission.
3. **Permission** — the `portal.*` grant.

A record that fails ownership answers **404, never 403**. A 403 confirms the
invoice exists, and invoice numbers are sequential.

## 4. Permissions added

| Permission | Held by |
| --- | --- |
| `portal.billing.view` | account-owner |
| `portal.billing.pay` | account-owner |
| `portal.orders.view` | account-owner, portal-member |
| `portal.payment_methods.manage` | account-owner |
| `portal.tokens.manage` | account-owner (high risk) |

`portal-member` — a technical contact, an employee — sees orders and their
own profile and nothing financial. That is the WHMCS "contact permissions"
idea, expressed as roles rather than as eight checkboxes on a contact.

## 5. Screens

```text
Dashboard        unpaid invoices, account credit, recent orders,
                 placeholders that say what is coming rather than showing
                 an empty table

Billing
 ├─ Invoices     list with status filter, outstanding grouped by currency
 ├─ Invoice      lines, payments, credit notes, and the pay panel
 ├─ Transactions the ledger that explains the credit balance
 ├─ Credit       balance per currency and how it was reached
 └─ Details      billing address, tax id, billing email

Orders           list and detail, the copy of the catalog the order froze

Account
 ├─ Profile      exists
 ├─ Contacts     exists
 └─ Security     exists, shared with staff at /security

Developer
 └─ API tokens   create, list, revoke — scoped to the customer, abilities
                 reserved for Phase 10
```

The navigation follows the handoff's client map (§3). Sections whose phase
has not happened are **absent, not disabled**: a nav item that leads to
"coming soon" is worse than no nav item, and the shell already proved it can
add rows without moving the others.

## 6. Payment methods, and what this phase will not do

Phase 4 built the `payment_methods` table, the model with its token hidden,
and a Stripe adapter that declares the capability. This phase will **list,
set default and remove** a stored method.

It will **not add one**. Collecting a card means a hosted field from the
gateway, and the platform's rule is that raw card data never reaches it at
all — not the server, not a log, not a validation error. The honest way in
is a gateway-hosted setup flow, which needs a real Stripe account to be
anything other than a guess. Deferred, named in the result document, and the
screen says so rather than offering a button that fails.

## 7. The dashboard

Six things a customer opens the portal to find out, in the order they matter:

1. Do I owe anything, and when is it due.
2. Is anything of mine about to stop working. *(Phase 6)*
3. What did I last buy, and did it go through.
4. Do I have credit.
5. Is anything waiting on me — a ticket reply, a domain to verify.
   *(Phases 7, 8)*
6. What has changed on my account.

Only 1, 3 and 4 have data behind them today. The rest are not rendered as
empty tables; the dashboard shows what exists and stays short.

## 8. Testing

Beyond success and failure on every screen:

- **Isolation.** For every client route: a contact from another customer
  gets 404, and a contact from another organization gets 404. Written once
  as a dataset over the route list, so a route added later without the scope
  fails the suite.
- **Permission.** A `portal-member` cannot reach billing; an
  `account-owner` can.
- **Impersonation.** A staff member impersonating a customer cannot create
  an API token or change contacts — the existing `impersonation.blocked`
  middleware, extended to the new destructive routes.
- **The presenter drops what it should.** An invoice's internal notes and a
  transaction's operator reason never reach the client payload; asserted on
  the rendered props, not by reading the code.
- **Money.** Totals on the client screen equal the admin's for the same
  invoice, asserted against the same row.

## 9. Order of work

1. `ForCurrentCustomer` resolution, the permissions, the nav restructure.
2. Billing: invoices list and detail, folding in the pay page the storefront
   already has.
3. Transactions, credit, billing details.
4. Orders list and detail.
5. Developer: API tokens.
6. Payment methods: list, default, remove.
7. Dashboard, last, because it is a composition of the five above.
8. Translations, arch tests, result document.
