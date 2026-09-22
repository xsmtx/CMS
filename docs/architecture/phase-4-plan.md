# Phase 4 — Billing + Payments Plan

Status: approved for implementation
Date: 2026-09-25
Scope: V2 roadmap Phase 4 — the invoice engine, credits and refunds, the
payment gateway contract, a manual gateway, a Stripe adapter, webhook
idempotency and reconciliation. Recurring billing runs (renewal invoices and
dunning) belong to Phase 9's automation and are out of scope; the machinery
they will call is built here.

---

## 1. Starting point

Phase 3 leaves an order that reaches `awaiting_payment` and stops. This is
the phase that lets money move, which makes it the phase where mistakes are
expensive and permanent.

Three rules already in force decide most of the design:

- **Money is integer minor units** ([ADR 0014](../adr/0014-money-representation.md)).
- **Lines copy, they do not reference** ([ADR 0021](../adr/0021-order-lines-copy-the-catalog.md)).
  Invoice lines copy from the order line, one step further along the same
  chain.
- **Tax is a contract** ([ADR 0022](../adr/0022-risk-and-tax-are-contracts.md)).
  Core never learns a country's rules; an invoice records what was charged,
  not how it was decided.

## 2. The central decision: an issued invoice is frozen

A draft invoice is editable. **The moment it is issued it stops being a
working document and becomes a record**: the customer's name, address and
tax details are copied onto it, every line's wording and amount is fixed,
and the totals never recompute.

This is not a nicety. An invoice is a tax document in most of the world, and
a document whose numbers move is not evidence of anything. It also means the
customer record can be corrected, the catalog can be repriced and the tax
rate can change without a single issued invoice shifting.

Corrections after issue happen the way accounting does them: a **credit
note**, which is its own numbered document referencing the original. Nothing
issued is ever edited or deleted.

## 3. Documents

```text
Order ──issues──> Invoice ──paid by──> Payment(s)
                     │                     │
                     ├──> InvoiceItem      └──> Transaction (append-only ledger)
                     └──> CreditNote ──> Transaction
```

| Table | Notes |
| --- | --- |
| `invoices` | Number, customer, currency, status, dates (issued, due, paid), snapshot of the bill-to party, subtotal/discount/tax/total, amount paid, tax breakdown, notes, terms |
| `invoice_items` | Copied description, quantity, unit and line amounts, tax, and the order item it came from |
| `payments` | Invoice, gateway, amount, status, provider reference, received/failed timestamps, the raw provider payload keyed for support |
| `transactions` | Append-only ledger: customer, kind, amount, currency, balance after, source document |
| `credit_notes` | Number, invoice, reason, amounts, issued date |
| `gateway_events` | Deduplication of provider event ids, with the payload and the outcome |
| `payment_methods` | A stored reference to a card or account held **at the gateway**; never a number |

**No card data touches this platform.** A payment method row holds a
gateway's token, the last four digits and a brand, which is what a customer
needs to recognise it and nothing a thief can use.

## 4. Numbering

Invoices, credit notes and receipts each take a sequence from the
`number_sequences` table built in Phase 3, allocated with a row lock inside
the transaction that writes the document. A number handed to a transaction
that rolls back leaves a gap nobody can explain to an auditor.

Sequences are per organization and configurable in prefix and padding,
because an operator's accountant usually has opinions.

## 5. The invoice state machine

```text
draft ──> unpaid ──> partially_paid ──> paid
             │              │
             ├──> overdue ──┘
             └──> cancelled          paid ──> refunded
```

- `draft` is the only editable state.
- `unpaid` means issued. A document exists.
- `overdue` is `unpaid` past its due date; it is a status rather than a
  computed flag so that dunning in Phase 9 has something to transition from.
- Partial payment is normal, not an error. An invoice tracks `amount_paid`
  and derives what remains.
- Overpayment does not fail either: the excess becomes account credit, which
  is a transaction like any other.

## 6. Payments and the ledger

Every movement of money writes an **append-only transaction**. The ledger is
the truth and an invoice's `amount_paid` is a cached total of it, rebuildable
from the rows at any time.

Transaction kinds: `payment`, `refund`, `credit_added`, `credit_applied`,
`credit_note`, `adjustment`.

A payment does not mark an invoice paid by itself. `RecordPayment` writes the
transaction, recalculates the invoice from the ledger, transitions it if the
balance reached zero, and — when the invoice came from an order — moves the
order to `paid`. One path, so an invoice cannot be paid without the order
noticing.

## 7. The gateway contract

```php
interface PaymentGateway
{
    public function capabilities(): GatewayCapabilities;
    public function createPayment(PaymentIntent $intent): PaymentResult;
    public function refund(RefundRequest $request): RefundResult;
    public function verifyWebhook(WebhookRequest $request): ?GatewayEvent;
}
```

Rules the handoff states and this phase enforces with tests:

- **Never trust a redirect as proof of payment.** The return URL marks
  nothing paid; it shows a "we are checking" page. Only a verified webhook
  or a server-side confirmation moves money.
- **Verify signatures** before parsing a payload.
- **Deduplicate on the provider's event id.** A gateway that delivers the
  same event five times must produce one payment.
- **Idempotency keys** on outbound calls, so a retried request cannot charge
  twice.
- No transaction is held open across a remote call.

Adapters this phase: `ManualGateway` (bank transfer — an operator records
what arrived) and `StripeGateway` (Payment Intents, tested against faked
HTTP). PayPal is deferred with its reason stated in the result document
rather than half-built.

## 8. PDF

`InvoiceDocument` renders through the same `StorefrontRenderer`-style seam so
a theme can replace the template. The renderer is a contract with a Blade
implementation; producing the actual PDF goes through a contract too, so an
installation without a headless browser still gets HTML it can print.

## 9. Permissions added

| Slug | Notes |
| --- | --- |
| `billing.invoices.view` | |
| `billing.invoices.manage` | Create, edit a draft, issue, cancel |
| `billing.payments.record` | High risk: it asserts money arrived |
| `billing.refunds.manage` | High risk: it moves money out |
| `billing.credits.manage` | High risk: it is money |

## 10. Screens

**Admin:** invoices list with status filters, invoice detail with lines,
payments and the ledger, record-a-payment, issue, cancel, refund, credit
note, and account credit on the customer page.

**Storefront/client:** a pay page for an invoice, reachable by the customer
it belongs to. The full client billing area is Phase 5.

## 11. Testing

- An issued invoice does not change when the customer, the catalog or the
  tax rate changes.
- Partial payments sum; an overpayment becomes credit.
- The ledger rebuilds an invoice's paid amount exactly.
- Every legal and illegal invoice transition.
- A webhook delivered five times produces one payment.
- A webhook with a bad signature is refused and recorded.
- A redirect return marks nothing paid.
- A refund cannot exceed what was paid.
- Numbers are unique under concurrency.
- Organization isolation on invoices, payments and transactions.

## 12. Order of work

1. Invoice domain: statuses, transaction kinds, the gateway contracts.
2. Migrations, models, factories.
3. Invoice generation from an order, numbering, the state machine.
4. Payments, the ledger, credits and refunds.
5. The gateway contract, the manual gateway, the Stripe adapter, webhooks.
6. Admin screens and the customer pay page.
7. ADRs, docs, result document.

Each step is a separate commit and leaves the suite green.
