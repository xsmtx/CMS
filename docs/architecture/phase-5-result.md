# Phase 5 — Client Area Result

Status: complete
Date: 2026-09-27
Plan: `phase-5-plan.md`
Next phase: Phase 6 (Services + Provisioning) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **599 passed, 2022 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 17 passed |
| Build | `vite build` | pass |

Phase 4 finished at 562 tests; this phase adds 37.

## 2. The two decisions this phase turns on

**The client area reads what the admin reads.** There is no customer-facing
copy of anything: a client screen resolves the same models through the same
services, narrowed by one condition. Where a customer needs less — an
invoice without its internal notes, a ledger without an operator's reason —
the **presenter** drops fields and the query stays the same. A customer told
they owe 149.90 and an operator told they owe 149.90 are reading the same
row, so the two numbers cannot drift.

**Authorization is three questions here, not two.** Boundary, then
**ownership**, then permission. A contact is inside their own customer's
organization and holds `portal.billing.view`; neither fact says anything
about *which* invoice they asked for. Every client screen resolves its
records through `CurrentCustomer`, so forgetting the ownership condition
means forgetting to use the class at all — visible in review in a way a
missing `where` is not. A record that fails ownership answers **404, never
403**: a 403 confirms the invoice exists, and invoice numbers are
sequential.

## 3. Problems found

**The front end had no translations, and the first attempt at giving it
some published the fraud rules.** Every user-facing string belongs in
`lang/en` and `lang/tr`, but the Vue layer had no way to read one, so admin
screens carried English literals. The fix embeds the messages in the
document once per load rather than on every Inertia navigation — and the
first version shipped whole language *files*, which put the wording of
every risk rule ("`:count` orders in the last `:hours` hour(s)", "Billing
country `:billing` does not match request origin `:origin`") into the page
source of every customer's browser. A language file is not written for one
audience.

`FrontEndTranslations` is now an **allow-list of paths**, not of files:
adding `t('billing.something')` to a component means adding its path there,
so the decision to publish a string is made once, visibly, in one file. The
payload is 7.5 KB and carries no operator vocabulary. It was caught by a
test asserting that a customer is never told why their order was held.

**Two defensive branches were dead code.** `CurrentCustomer` guarded
against a contact with no customer; `contacts.customer_id` is not nullable
and the relation always resolves. The guard was removed rather than left as
a comment claiming a state that cannot happen — and the test written for it
was deleted rather than contorted into passing.

## 4. What was built

### The shell

Navigation follows the handoff's client map, with one rule: a row exists
when the screen behind it exists. Services, Domains and Support are
**absent**, not disabled — a nav item leading to "coming soon" teaches a
customer that the navigation lies. Rows are also filtered by permission, so
a portal member sees four destinations and an account owner seven.

### Dashboard

What is owed, soonest first; recent orders; credit when there is any. A zero
credit balance is not news, so the panel is absent rather than rendered
empty. A contact who cannot see billing does not get a total owed on the
front page instead.

### Billing

- **Invoices** — list with status filters, outstanding grouped per
  currency, never summed across them. Drafts are not listed: nothing has
  been claimed from the customer until a document is issued.
- **Invoice** — lines, payments, credit notes and the pay panel, which
  posts to the same endpoint the storefront uses. One payment path, whether
  the customer arrived from checkout or from their own list.
- **Transactions** — the ledger, with the running credit balance beside
  each row and the sign taken from the kind rather than from the stored
  amount.
- **Details** — the billing address and tax id the *next* invoice will be
  made out to, saying so plainly, because an issued invoice keeps what it
  was issued with ([ADR 0023](../adr/0023-issued-documents-are-frozen.md)).

### Orders

The list Phase 3 deliberately left out, and the detail: the copy of the
catalog the order froze ([ADR 0021](../adr/0021-order-lines-copy-the-catalog.md)),
which is the point of reading an old order at all. The risk decision never
appears — telling a customer which rule held their order is telling whoever
is probing the rules.

### Developer

API tokens, issued by the customer to themselves. Shown **once**, on the
redirect that created it; the table stores a hash, so there is nothing to
show later even if a screen asked. Issuing and revoking are both audited
with the token's name. A staff member impersonating a customer cannot issue
one: whatever the impersonation is for, it is not to walk out with a
credential that outlives the session.

### Permissions

`portal.billing.view`, `portal.billing.pay`, `portal.orders.view`,
`portal.payment_methods.manage` and `portal.tokens.manage` (high risk).
`portal-member` gains orders and nothing financial — the WHMCS idea of
contact permissions, expressed as a role rather than eight checkboxes on a
contact.

## 5. Files

```text
app/Support/Identity/     CurrentCustomer
app/Support/View/         FrontEndTranslations
app/Http/Controllers/     Client/{Dashboard,Invoice,Transaction,
                          BillingDetails,Order,ApiToken}
app/Http/Requests/Client/ BillingDetailsRequest, ApiTokenRequest
resources/js/composables/ useTranslations
resources/js/Pages/Client Dashboard, Billing/{Invoices,Invoice,Transactions,
                          Details,BillingTabs}, Orders/{Index,Show},
                          Developer/Tokens
lang/{en,tr}/             portal.php (new), billing, ordering, identity, crm
routes/client.php         billing, orders, developer
```

## 6. Not done, and why

| Item | Detail |
| --- | --- |
| **Adding a payment method** | Listing, defaulting and removing are built. Adding one means a gateway-hosted field, because raw card data never reaches this platform — not the server, not a log, not a validation error. A form that pretended otherwise would be the worst thing in the codebase. It needs a real Stripe account to be anything but a guess, and the screen says so instead of offering a button that fails. |
| **Services, Domains, Support** | Phases 6, 7 and 8. The shell adds their rows when the screens exist. |
| **Notification preferences** | The four `notify_*` flags are already on the profile screen from Phase 1. A per-event matrix belongs with Phase 8's notification system, which is what would send them. |
| **Webhooks, API documentation, API activity** | Phase 10, with the public API the tokens will reach. |
| **Downloading an invoice as PDF** | Phase 11, with the themeable templates. The screen prints acceptably. |
| **The admin front end's own strings** | `useTranslations` exists and the client area uses it throughout; the admin screens still carry English literals from earlier phases. Converting them is mechanical and belongs with Phase 11's theming work, where the strings are being touched anyway. |

## 7. Carried risks

| Item | Detail |
| --- | --- |
| **The client screens have not been driven in a browser** | Every screen is asserted through Inertia's page-prop testing, which proves what the server sends and not what Vue draws. Signing in requires typing a password, which I do not do. Three of the six bugs in earlier phases were found only by walking the UI, so this is a real gap and the screens deserve one pass by hand. |
| **`CurrentCustomer` is a convention, not a compiler** | A controller can still query a model directly and forget the ownership condition. `ClientIsolationTest` sweeps every client GET route from the router rather than from a list, which catches a new route that answers for somebody else's record — but only for routes that take an identifier. |
| **Contact-level permissions are two roles, not a matrix** | `account-owner` and `portal-member` cover the common split. A customer who wants a bookkeeper who sees invoices but not orders needs a third role, which the role system supports and nothing yet offers in the portal. |
| **The translation payload is per document, not per surface** | A storefront visitor is sent the client area's strings too. 7.5 KB, gzipped with the document, and splitting it per surface would put a decision in the middle of a hot path to save almost nothing. |

## 8. Exact next recommended task

**Phase 6 — Services + Provisioning**, first slice: the service lifecycle
that every screen in this phase has a hole shaped like.

1. A `Service` with a state machine — pending, active, suspended,
   terminated — created from a paid order line.
2. The provisioning contract, with a module behind it, and the queue that
   calls it: idempotent, bounded retries, no transaction held across a
   remote call.
3. Infrastructure inventory and placement, so a service knows where it
   lives.
4. The client area's Services section, which is the row the navigation is
   deliberately missing today.
