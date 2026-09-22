# Phase 3 — Cart + Checkout + Orders + Risk Plan

Status: approved for implementation
Date: 2026-09-24
Scope: V2 roadmap Phase 3 — cart, the domains-in-cart abstraction,
promotions, checkout, the tax interface, the order state machine and the risk
engine. Payment capture (Phase 4), provisioning adapters (Phase 6) and
registrar integration (Phase 7) are out of scope.

---

## 1. Starting point

Phase 2 left a catalog that can describe and price anything, and a storefront
that can show it. Nothing can be bought. This phase is the path from "this
plan costs 149.90 TRY a month" to an order record that Phase 4 can invoice
and Phase 6 can provision.

Three rules from earlier phases shape every decision here:

- **Money is integer minor units** ([ADR 0014](../adr/0014-money-representation.md)).
  Every total, discount and tax amount in this phase is a `Money`.
- **A price exists only where a row exists** ([ADR 0019](../adr/0019-price-matrix.md)).
  The cart composes from the same matrix the storefront reads; it never
  invents a price for a currency or cycle that was not entered.
- **Financial history is append-only.** An order's status history and its
  line prices are written once.

## 2. The central decision: lines copy, they do not reference

An order line stores the product's name, the option labels, the cycle, the
currency and every amount **as they were at the moment of ordering**. It
keeps the catalog ids too, so reporting can group by product, but nothing
about what the customer agreed to pay is read back through them.

This is the single most consequential choice in the phase. A referenced price
means an operator raising a price rewrites what past customers agreed to; it
means a deleted option makes an old order unreadable; and it means an invoice
issued in March changes in April. Copying costs a wider table and buys a
record that stays true.

Phase 4 inherits the same rule for invoice lines and will copy from the order
line rather than from the catalog.

## 3. Cart

A cart is a persisted record, not a session array: a visitor who signs in
mid-checkout keeps their cart, and an operator can see an abandoned one.

```text
Cart                      owner (contact or anonymous token), currency, expiry
  └─ CartItem             product, cycle, quantity
       ├─ option choices  option group key → option value, with the delta
       └─ addons          addon id, with its own price
```

| Table | Notes |
| --- | --- |
| `carts` | Organization, optional contact, anonymous token, currency, promotion, expiry, timestamps |
| `cart_items` | Cart, kind (product/domain), product, billing cycle, quantity, domain name, per-line snapshot of the resolved amounts |
| `cart_item_options` | Cart item, option group, option, label and value at the time of adding |
| `cart_item_addons` | Cart item, addon, and its resolved amounts |

The cart's currency is fixed when the first item goes in. Mixing currencies in
one cart is refused rather than converted, for the same reason the storefront
does not convert.

**Domains in the cart are an abstraction, not a registrar.** A cart item of
kind `domain` carries a domain name, a TLD, a term in years and a price that
comes from a `DomainPricing` contract. Phase 3 ships a null implementation
that reports every domain unavailable and prices nothing, so the shape is
fixed and tested before Phase 7 puts a registrar behind it. Checkout accepts a
domain line only when the contract priced it.

## 4. Pricing a cart

One use case, `PriceCart`, produces a `CartTotals`:

```text
for each item:
    base        = product price for (cycle, currency)
    options     = sum of signed option deltas for (cycle, currency)
    addons      = sum of addon prices for (cycle, currency)
    setup       = product setup + option setup + addon setup
    line total  = (base + options + addons) × quantity
discount        = promotion applied to the eligible subtotal
tax             = tax provider's answer for (subtotal − discount, place of supply)
total           = subtotal − discount + tax + setup
```

Every step is `Money`. The discount is allocated across lines with the
largest-remainder split already in `Money::allocate()`, so the line amounts
sum exactly to the order total — which is what makes a per-line invoice in
Phase 4 reconcile.

Recurring and one-off amounts are tracked separately throughout. "149.90 now,
149.90 every month" and "299.80 now" are different statements and the
storefront has to be able to make the first one.

## 5. Promotions

| Table | Notes |
| --- | --- |
| `promotions` | Code, type (fixed/percentage), amount, currency for fixed amounts, scope, applies-to cycles, first-payment or recurring, starts/ends, usage limit, per-customer limit, minimum subtotal, new-customers-only, stackable, active |
| `promotion_products` | Which products a scoped promotion applies to |
| `promotion_redemptions` | Append-only: promotion, order, customer, amount, redeemed_at |

Rules from the handoff, each with a test:

- Fixed amounts are in a currency and apply only to a cart in that currency.
  A fixed 50 TRY discount is meaningless on a EUR cart, and converting it
  would be the same mistake as converting a price.
- A percentage discount is computed on the eligible subtotal, rounded half up
  once, at the end — never per line and then summed.
- `first_payment` discounts the first invoice only; `recurring` sets a flag
  the service carries so Phase 9's renewal honours it.
- Usage limits and per-customer limits are checked at redemption inside the
  order transaction, not when the code is typed. Checking at type time is a
  race that oversells the last redemption.
- Redemption is append-only and carries the amount, so "what did this
  promotion cost us" is a query rather than a reconstruction.

## 6. Tax

`App\Domain\Tax\TaxCalculator` is a contract, and core never implements a
country's rules:

```php
interface TaxCalculator
{
    public function calculate(TaxableSupply $supply): TaxResult;
}
```

`TaxableSupply` carries the amounts, the customer's place of supply, their tax
id and whether they are a business. `TaxResult` carries zero or more named
components (a rate and an amount each), so a jurisdiction with two taxes on
one line is expressible.

Phase 3 ships two implementations: `NoTaxCalculator` (the default, answering
zero) and `FlatRateTaxCalculator`, configured with a rate and a name, which is
enough for a single-country installation. A module registers its own in
Phase 12. Nothing in core asks "is this customer in the EU".

## 7. Orders

```text
draft → pending → awaiting_payment → paid → provisioning → active
                ↘ fraud_review ↗          ↘ partially_fulfilled → active
                ↘ payment_review ↗
  any → cancelled | failed        paid → refunded
```

| Table | Notes |
| --- | --- |
| `orders` | Number, organization, customer, contact, currency, status, subtotal/discount/tax/setup/total in minor units, promotion, risk decision, placed_at, IP and user agent at placement |
| `order_items` | Order, kind, copied product name and id, cycle, quantity, unit and line amounts, setup, domain name and term |
| `order_item_options` | Copied option group name, option label and value, and the delta |
| `order_status_history` | Append-only: from, to, actor, reason, occurred_at |

The state machine is an enum with an explicit `canTransitionTo()`, in the
shape Phase 1 already uses for customer status, and every transition writes
both a history row and an audit entry.

Order numbers get their own sequence table, allocated inside the placing
transaction with a row lock. Phase 4 reuses the same machinery for invoice
numbers, which is why it is built properly here rather than with a count.

## 8. Checkout

Three things happen in one transaction: an account exists, an order exists,
and the cart is spent.

- A signed-in contact orders against their own customer.
- A visitor supplies their details and gets a customer, a contact and a
  password-less account, and a verification mail. **This is where email
  verification enforcement lands**, carried from Phase 1: a contact created
  at checkout cannot sign in until the address is verified, and the order
  proceeds regardless — refusing the order because an inbox is slow would be
  absurd.
- Terms acceptance is recorded with the version and the timestamp, because
  "did they accept" is a question that gets asked a year later.
- The domain is collected where the product type requires one.

Checkout re-prices the cart server-side and compares against the total the
browser showed. A mismatch stops the order: a price that changed underneath a
customer is a conversation, not a silent charge.

## 9. Risk

`App\Domain\Risk\RiskEvaluator` returns `allow`, `review` or `deny` with
reasons, and core ships `RuleBasedRiskEvaluator` reading signals it already
has: order value against a threshold, account age, velocity (orders from one
customer or address in a window), and a mismatch between billing country and
request address. No vendor is named anywhere in ordering.

The decision and its reasons are persisted on the order. `review` routes to an
admin queue where a staff member with `orders.review` can override, and the
override is audited with its reason. `deny` cancels the order and says so
without explaining which rule fired — a rejection message that names the rule
is a tuning guide for whoever triggered it.

## 10. Permissions added

| Slug | Notes |
| --- | --- |
| `orders.view` | |
| `orders.manage` | Change status, cancel |
| `orders.review` | Clear or deny a risk hold — high risk |
| `promotions.view` | |
| `promotions.manage` | High risk: it changes what customers are charged |

## 11. Screens

**Storefront:** configure a product (cycle, options, addons, quantity, domain),
cart, checkout, order confirmation.

**Admin:** orders list with status filters, order detail with the line
breakdown and status history, the risk review queue, promotions list and form.

## 12. Testing

- Money composition: a product with two options and an addon, across cycles
  and currencies, with the totals asserted exactly.
- Every promotion rule, including the ones that refuse: wrong currency,
  expired, limit reached, minimum not met, not a new customer.
- Discount allocation sums exactly to the order total.
- Every legal and illegal state transition.
- Checkout: signed-in, new visitor, price changed underneath, cart from
  another organization, empty cart, currency mismatch.
- Risk: each rule, the review queue, the override, and the audit entries.
- Order numbers are unique under concurrency.
- Organization isolation on carts, orders and promotions.

## 13. Order of work

1. Order and cart domain enums, the state machine, the tax and risk
   contracts.
2. Migrations, models, factories.
3. Cart use cases and pricing, with the promotion and tax engines.
4. Storefront: configure, cart and checkout screens.
5. Order placement, numbering, risk evaluation.
6. Admin: orders, the review queue, promotions.
7. ADRs, docs, result document.

Each step is a separate commit and leaves the suite green.
