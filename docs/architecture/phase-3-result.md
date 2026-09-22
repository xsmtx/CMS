# Phase 3 — Cart + Checkout + Orders + Risk Result

Status: complete
Date: 2026-09-25
Plan: `phase-3-plan.md`
Next phase: Phase 4 (Billing + Payments) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **443 passed, 1377 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 17 passed |
| Build | `vite build` | pass |
| Migrations | 16 migrations on MariaDB 11.8 | clean |

Phase 2 finished at 329 tests; this phase adds 114.

## 2. The decision this phase turns on

**An order line copies the catalog, it does not reference it**
([ADR 0021](../adr/0021-order-lines-copy-the-catalog.md)). The product name,
the option labels, the cycle, the currency and every amount are written onto
the line at the moment of ordering. A test renames a product after the order
and asserts the line still reads correctly; another deletes the product and
asserts the line survives with its id nulled.

A cart is deliberately the other way round: its lines reference the catalog
and are priced on every read, so a cart left open overnight shows this
morning's price. The copy happens once, at placement, and checkout re-prices
and compares against the total the browser showed before anything is
recorded.

## 3. Problems found

**The price form was not the only client/server mismatch.** Phase 2's lesson
held: the confirmation page compared `$order->contact_id !== $contact?->id`,
and for an order with no contact both sides were null, so the page concluded
the order belonged to whoever was looking. An order number is short enough
to guess at and it names a customer. Fixed to require a contact and a match;
a test now walks in as a stranger.

**`Money::allocate()` lost a unit on negative amounts.** `intdiv` truncates
toward zero, so splitting −100 across three parts gave −99. Invoices
reconcile; credit notes have to as well.

**A lazy-loading violation in the pricing path.** `$product->group?->name`
fetched one group per line. Strict mode caught it in the test that prices
two products; the relation is now eager-loaded, because the line copies the
group name and a per-line query is how a cart with twenty items becomes
forty.

**The nav highlighted two items at once.** `/admin/orders` is a prefix of
`/admin/orders/review`. The active item is now the longest match.

## 4. What was built

### Cart

- Persisted, not a session array: signing in halfway through keeps it, and
  an operator can see what was abandoned. Identified by a token in the
  session, claimed by the contact on sign-in.
- The currency is fixed by the first item. A second currency is refused
  rather than converted; an empty cart simply adopts the new one.
- Product lines, addon lines that hang off their product, and domain lines.
  Removing a product line takes its addons with it.

### Pricing

One use case composes a price out of a product, its options, its addons, a
promotion and tax. The cart screen, the checkout summary and the order that
gets written all call it, so none of them can disagree about the total.

- Option deltas are signed, so "no control panel" is genuinely cheaper.
- A quantity option multiplies its unit price by the number typed.
- An addon is billed on the cycle of the product it was bought with.
- Recurring and one-off amounts are tracked separately throughout, so the
  cart can say "14.99 now, then 9.99 a month" rather than one number that
  means neither.

### Promotions

Fixed and percentage; order, product and setup-fee scopes; first-payment or
recurring; date windows, total and per-customer limits, minimum order value,
new-customers-only.

- A percentage is computed **once**, on the eligible subtotal, then
  allocated across lines with the largest-remainder split from `Money`, so
  the per-line shares sum exactly to the order discount. Computing per line
  and summing rounds several times and produces a total nobody can explain.
- A fixed amount belongs to a currency and is refused on a cart in another
  one — converting it would be the same mistake as converting a price.
- A discount never exceeds what it discounts.
- Every refusal is a named reason. "That code is not valid" sends a customer
  away when what they needed to hear was that the order has to be ten lira
  larger.
- Redemption happens inside the order transaction behind a row lock. If
  someone took the last one in between, **the order stands and the discount
  does not** — a test asserts the customer pays the undiscounted total
  rather than losing the order.

### Tax and risk

Contracts with deliberately dull defaults
([ADR 0022](../adr/0022-risk-and-tax-are-contracts.md)). `NoTaxCalculator`
charges nothing; `FlatRateTaxCalculator` applies one configured rate;
`RuleBasedRiskEvaluator` reads signals the platform already has and holds
rather than refuses. Nothing denies outright unless an operator sets a deny
threshold.

### Orders

- Twelve states with explicit transitions, every one tested, including the
  ones that must not happen: a cancelled order does not move, a paid order
  does not go back to awaiting payment.
- Numbers from a locked sequence row inside the placing transaction. Phase 4
  reuses the same table for invoices.
- Append-only status history and an audit entry for every transition.
- Admin: list with status filters, detail with the line breakdown and
  history, and a review queue that is its own screen because it is a to-do
  list.
- Releasing or refusing a held order answers to its own permission and
  demands a reason.

### Checkout

- A signed-in contact orders against their own customer; a visitor gets a
  customer, a contact and a billing address.
- **No password field anywhere.** The account holds a random password nobody
  has ever seen, so it is reachable only through the reset flow — which is
  where email verification, carried since Phase 1, finally bites.
- Terms acceptance is recorded with its version and timestamp.
- The browser's total is carried and compared. A mismatch stops the order.

### Admin menu

Restructured to the admin panel map in the specification, which is the shape
a WHMCS operator already knows: Dashboard, Customers, Orders, Billing,
Products, System. Only sections that exist are listed — a menu that
advertises Billing before invoices exist is a menu that lies. A light/dark
switch was added at the same time; it costs almost nothing because every
colour is already a semantic token with a dark set defined.

## 5. Files

```text
app/Domain/Ordering/      OrderStatus, LineKind
app/Domain/Promotions/    PromotionType, Scope, Application, Refusal
app/Domain/Risk/          RiskDecision, RiskAssessment, RiskReason,
                          RiskSubject, Contracts/RiskEvaluator
app/Domain/Tax/           TaxableSupply, TaxComponent, TaxResult,
                          Contracts/TaxCalculator
app/Domain/Domains/       DomainName, DomainQuote, Contracts/DomainPricing
app/Application/Ordering/ PriceCart, CartTotals, PricedLine, PricedOption,
                          AddToCart, UpdateCartItem, ResolveCart,
                          ApplyPromotionCode, PlaceOrder, TransitionOrder,
                          EvaluateOrderRisk, RegisterCheckoutAccount,
                          Exceptions/
app/Application/Promotions/ PromotionEngine, DiscountResult, SavePromotion,
                          DeletePromotion, PromotionAttributes
app/Application/Shared/   AllocateNumber
app/Infrastructure/       Ordering/Models, Promotions/Models,
                          Shared/Models/NumberSequence, Tax/, Risk/,
                          Domains/NullDomainPricing
app/Http/                 Controllers/{Admin/Order,Admin/OrderReview,
                          Admin/Promotion,StorefrontCart,StorefrontCheckout},
                          Requests/Ordering/, Concerns/PresentsCartTotals
app/Policies/             Order, Promotion
app/Providers/            OrderingServiceProvider
database/migrations/      promotion tables, cart tables, order tables
resources/js/             Pages/Admin/{Orders,Promotions},
                          Components/ThemeSwitch, composables/useTheme
resources/views/          storefront/{configure,cart,checkout,
                          order-confirmation}
lang/{en,tr}/             ordering.php
docs/adr/                 0021, 0022
```

## 6. Divergences from the plan

| Plan said | What was built | Why |
| --- | --- | --- |
| Cart items carry "a per-line snapshot of the resolved amounts" | Product and addon lines carry no amounts and are priced on every read; only domain lines carry a quote | A snapshot in the cart goes stale while the customer reads the page, and checkout re-prices anyway. Domains are the exception because their price comes from outside the catalog. |
| Addons as a column on the product line | Addons are their own line with a `parent_id` | They are billed on their own line, which is how Phase 4 will invoice them. |

## 7. Not done, and why

| Item | Detail |
| --- | --- |
| **Payment capture** | Phase 4, by design. An order reaches `awaiting_payment` and stops. |
| **Domains as a real thing** | The contract, the value objects and the cart line exist and are tested; `NullDomainPricing` prices nothing, so a domain cannot reach a cart until Phase 7 binds a registrar. The shape is fixed now so that phase adds an adapter rather than a schema. |
| **Stackable promotions** | The column and the refusal reason exist; only one code applies to a cart. Stacking needs an order-of-application rule that is a business decision, not a default. |
| **Recurring promotions at renewal** | `application` is stored and carried onto the order. Honouring it at renewal is Phase 9's job, where renewals exist. |
| **Client-area order history** | Phase 5. A customer sees their confirmation; the list of past orders belongs with the rest of the client area. |
| **Custom field, tag, address and note admin screens** | Still carried from Phase 1. |
| **Failed payment signals for risk** | `RiskSubject` carries `failedPayments` and the rule reads it; nothing populates it until payments exist in Phase 4. |

## 8. Carried risks

| Item | Detail |
| --- | --- |
| **Order numbers are per organization** | Two resellers both start at ORD-000001. Correct today; if Phase 11 gives resellers their own invoices, the sequence key may need a prefix per organization. |
| **Abandoned carts are never swept** | They expire after thirty days but nothing deletes them. A scheduled cleanup belongs with the rest of Phase 9's housekeeping. |
| **Option keys are still editable after an order references one** | Flagged in Phase 2 and now real: an order line copies the key, so changing it does not corrupt history, but it does break the join Phase 6 will use for provisioning. The field should lock once an order exists. |
| **The review queue has no ageing** | An order held on Friday looks the same as one held three weeks ago. A "waiting since" column is cheap and worth adding before the queue is ever long. |
| **Checkout collects a country but not a full address** | Enough for tax and risk. Invoicing in Phase 4 needs the rest, and the form will grow there rather than collecting fields nothing uses yet. |

## 9. Exact next recommended task

**Phase 4 — Billing + Payments**, first slice: invoices from orders.

1. `invoices` and `invoice_items`, numbered from the same sequence table,
   with the lines **copied from the order lines** — the same rule as
   [ADR 0021](../adr/0021-order-lines-copy-the-catalog.md), one step further
   along.
2. An invoice snapshots the customer's name, address and tax details, so a
   document reprinted next year is the document that was issued.
3. The payment gateway contract: capabilities, create payment, refund,
   webhook. A manual/bank-transfer adapter first, so an operator can mark an
   invoice paid through the same path a gateway uses rather than a special
   case.
4. Webhook idempotency on the provider's event id, and an order that reaches
   `paid` only through a recorded payment.
5. Transactions and the append-only ledger the customer-credit work in
   Phase 5 will read.
