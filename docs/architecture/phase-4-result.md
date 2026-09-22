# Phase 4 — Billing + Payments Result

Status: complete
Date: 2026-09-26
Plan: `phase-4-plan.md`
Next phase: Phase 5 (Client Area) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **547 passed, 1684 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 17 passed |
| Build | `vite build` | pass |
| Migrations | 18 migrations on MariaDB 11.8 | clean |

Phase 3 finished at 443 tests; this phase adds 104.

## 2. The two decisions this phase turns on

**An issued document is frozen** ([ADR 0023](../adr/0023-issued-documents-are-frozen.md)).
Issuing takes a number from the sequence, copies the bill-to party onto the
invoice and fixes every amount. A test renames the customer and changes
their tax id afterwards, then asserts the invoice still says what it said.
Corrections are credit notes: numbered, append-only documents of their own.

**The ledger is the truth, and a redirect is not**
([ADR 0024](../adr/0024-the-ledger-is-the-truth.md)). Every movement of
money is an append-only row with a positive amount, and the kind decides
which way it moves. An invoice's paid amount is a cache rebuilt from those
rows on every change. Nothing is marked paid by a browser coming back from a
gateway — a test returns to `?success=true&paid=1` and asserts the invoice
has not moved.

## 3. Problems found

**A NOT NULL column with a database default is still absent from the model
in memory after `create()`**, and the money cast reads that absence as null.
It surfaced as `Money::minus(): Argument #1 must be of type Money, null
given` on a freshly created invoice. Fixed once, at the model, by declaring
`$attributes` defaults on `Invoice`, `Payment` and `InvoiceItem` — one
mechanism rather than remembering at every call site.

**Gateway events cannot be stamped with an organization.** A webhook arrives
before anyone knows which organization it concerns, and `BelongsToOrganization`
refuses to create a row without one. `GatewayEventRecord` now registers the
same named scope by hand, the way `AuditLog` and `LoginHistory` already do,
and is listed in the exemption the ownership test enforces. Refusing to
record an event until we can attribute it would lose exactly the evidence
needed for the ones we could not match.

## 4. What was built

### Invoices

- Raised from an order, with lines copied from the order lines — the same
  rule as [ADR 0021](../adr/0021-order-lines-copy-the-catalog.md), one step
  further along. Options are folded into the line description, because an
  invoice is read by a person and often by their accountant.
- A draft has no sequence number. A number handed to a document that may
  never be issued leaves a gap nobody can explain to an auditor.
- Seven states with explicit transitions: partial payment is a normal step,
  not an error, and `overdue` is a state rather than a computed flag so
  Phase 9's dunning has something to transition from.
- Proformas have their own sequence, because they are not tax documents.

### The ledger

- Append-only rows, always positive, signed by their kind.
- `Ledger::paidTowards()` rebuilds an invoice's paid amount; a test asserts
  the rebuilt number equals the cached column.
- Account credit with a running balance on every row, so a customer's
  balance is readable without summing the table.
- Overpayment becomes credit rather than an error. Applying credit never
  applies more than is owed — the rest stays credit.

### Payments, refunds and credit notes

- One path settles an invoice, whether the money came from a webhook or an
  operator. It recalculates from the ledger, transitions the invoice, and
  moves the order to `paid`.
- Refunds call the gateway **outside** the database transaction, and write
  the ledger only after the provider accepts.
- A refund cannot exceed what a payment took; a credit note cannot exceed
  what the invoice was for.

### Gateways

- A contract stating four rules, each with a test: a redirect is never
  proof, signatures are verified before payloads are parsed, events
  deduplicate on the provider's event id, outbound calls carry an
  idempotency key.
- `ManualGateway` — bank transfer, and a real gateway rather than a special
  case, so "an operator recorded a transfer" and "a card succeeded" travel
  the same path.
- `StripeGateway` — Payment Intents over the HTTP API, with the signature
  scheme implemented and tested against faked HTTP, including a replay
  outside the tolerance window and a body changed after signing.
- A gateway missing its credentials is never registered, so an operator is
  offered only what actually works.

### Screens

Admin: invoice list with status filters and outstanding grouped by currency;
invoice detail with lines, payments, the ledger and credit notes; record a
payment, refund one, apply credit, issue a credit note. Customer: a pay page
for an unpaid invoice, and a return page that confirms nothing.

## 5. Files

```text
app/Domain/Billing/       InvoiceStatus, PaymentStatus, TransactionKind,
                          GatewayCapabilities, PaymentIntent, PaymentResult,
                          RefundRequest, RefundResult, GatewayEvent,
                          GatewayEventType, WebhookRequest,
                          Contracts/PaymentGateway
app/Application/Billing/  CreateInvoiceFromOrder, IssueInvoice,
                          TransitionInvoice, Ledger, RecordPayment,
                          RefundPayment, AddCredit, ApplyCredit,
                          IssueCreditNote, StartPayment, HandleGatewayEvent,
                          Exceptions/
app/Infrastructure/       Billing/Models, Billing/GatewayRegistry,
                          Billing/Gateways/{Manual,Stripe}
app/Http/                 Controllers/Admin/{Invoice,InvoicePayment},
                          Controllers/Api/GatewayWebhookController,
                          Controllers/StorefrontInvoiceController,
                          Requests/Billing/
app/Policies/             Invoice
app/Providers/            BillingServiceProvider
database/migrations/      invoice tables, payment tables
resources/js/             Pages/Admin/Invoices/{Index,Show}
resources/views/          storefront/{invoice,invoice-returned}
lang/{en,tr}/             billing.php
docs/adr/                 0023, 0024
```

## 6. Not done, and why

| Item | Detail |
| --- | --- |
| **PayPal** | Deferred rather than half-built. Its flow differs enough from Stripe's — orders and captures rather than intents, a different signature scheme — that writing it without an account to test against would produce an adapter nobody has ever seen work. The contract it plugs into is finished and proven by two implementations. |
| **PDF rendering** | The invoice screen prints acceptably from the browser, which is what an operator needs today. A PDF contract with a renderer behind it belongs with the branded templates in Phase 11, where the layout is themeable. |
| **Recurring invoices and dunning** | Phase 9's automation. The machinery it calls — issuing, numbering, overdue as a state, the ledger — is built and tested here. |
| **Stored payment methods** | The table, the model and the token-hiding are done, and the Stripe adapter declares the capability. Nothing stores one yet, because that needs the client-area billing screens in Phase 5. |
| **Client billing area** | Phase 5. A customer can pay an invoice they hold the number for; the list of their invoices belongs with the rest of the client area. |
| **Late fees, partial-payment reminders** | Phase 9, with the rest of the automation. |
| **Custom field, tag, address and note admin screens** | Still carried from Phase 1. |

## 7. Carried risks

| Item | Detail |
| --- | --- |
| **The Stripe adapter has never talked to Stripe** | Its signature verification, payload mapping and idempotency headers are tested against faked HTTP, which proves the code and not the integration. It needs one run against Stripe's test mode before an installation takes real money through it. |
| **No reconciliation report** | Every payment carries its provider reference and every event is recorded, so reconciliation is possible; nothing presents it. A "gateway says X, we say Y" screen is cheap now and belongs with the reports in Phase 9. |
| **`paid_minor` is recalculated, never locked** | Two concurrent webhooks for the same invoice could both rebuild it. They would arrive at the same answer from the same rows, so the outcome is right, but a row lock on the invoice during settlement would make that an argument rather than a coincidence. |
| **Tax is charged on the invoice, not per line** | Correct for a single-rate installation and what the order already computed. A jurisdiction with different rates per line needs the tax to move onto the line, which the column is already there for. |
| **Credit is per currency with no conversion** | A customer with credit in EUR cannot spend it on a TRY invoice. That is deliberate — converting it would invent a rate — but an operator will eventually ask, and the answer is a documented refusal rather than silence. |
| **Invoice numbers restart per organization** | Same note as order numbers. If Phase 11 gives resellers their own billing, the sequence key may need a per-organization prefix. |

## 8. Exact next recommended task

**Phase 5 — Client Area**, first slice: what a customer sees after they buy.

1. A dashboard: active services (once Phase 6 exists), unpaid invoices,
   recent orders, account credit, announcements.
2. Billing: their invoices, their payments, their credit balance and the
   ledger rows that explain it — reading the same tables the admin does,
   scoped to their own customer.
3. Orders: the list Phase 3 deliberately left out.
4. Stored payment methods, which is where the Stripe capability built here
   finally gets used.
5. The developer section: API tokens, scoped to the customer, ready for
   Phase 10's public API.
