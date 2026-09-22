# Phase 2 — Catalog + Storefront Plan

Status: approved for implementation
Date: 2026-09-23
Scope: V2 roadmap Phase 2 — products, groups, pricing, configurable options,
addons, currencies, the storefront catalog and the theme foundation.
Phase 3 (Cart + Checkout + Orders + Risk) is out of scope.

---

## 1. Starting point

Phases 0 and 1 left the organization boundary, permission registry, audit
trail, error envelope and two-guard identity in place. Every table this phase
adds is organization-owned from its first migration, and every screen gets a
policy that asks the boundary question before the permission question.

ADR 0014 fixed money as integer minor units plus an ISO 4217 code, before any
monetary code existed. This is the phase that honours it.

## 2. Money

`brick/money` is installed and wrapped, not used directly across the codebase.

- `App\Domain\Shared\Money` is the platform's value object: an integer amount
  of minor units and a `Currency`. It refuses to operate across currencies,
  and it has no `toFloat()`.
- A `MoneyCast` stores it as two columns, `*_minor` (bigint) and a currency
  code, so a price is never half-written.
- Allocation across lines uses largest-remainder distribution, so the parts
  always sum exactly to the whole. This matters the moment a discount is
  split over invoice lines in Phase 4; getting it right now means Phase 4
  inherits it rather than inventing it.
- Currencies with other exponents are handled by the currency's own exponent.
  JPY has none and KWD has three; assuming two decimals is a bug waiting for
  the first Japanese customer.

## 3. Catalog model

```text
ProductGroup            a section of the storefront
  └─ Product            what a customer buys
       ├─ ProductPrice  one row per billing cycle per currency
       ├─ OptionGroup   configurable choices (disk size, control panel)
       │    └─ Option   one choice, with its own price deltas
       └─ Addon         something bought alongside, with its own prices
```

| Table | Notes |
| --- | --- |
| `product_groups` | Name, slug, description, position, visibility, organization-owned |
| `products` | Group, name, slug, type, description, setup/recurring pricing, stock, visibility, position |
| `product_prices` | Product, billing cycle, currency, recurring and setup amounts in minor units |
| `option_groups` | Product, name, type (select/radio/quantity/checkbox), required flag, position |
| `options` | Option group, label, value, position, and its own price rows |
| `option_prices` | Option, billing cycle, currency, amounts |
| `addons` | Optional extra attached to a product, with its own prices |
| `addon_prices` | Addon, billing cycle, currency, amounts |
| `currencies` | Code, name, symbol, exponent, rate to the base currency, is_base |
| `exchange_rate_snapshots` | Append-only: currency, rate, source, captured_at |

Billing cycles are an enum: one-time, monthly, quarterly, semiannual,
annual, biennial, triennial. Metered is deliberately absent; it arrives with
usage collection, not with the catalog.

A product with no price in a currency is simply not sellable in it. The
alternative — converting at display time — produces a price that changes
between the page and the cart.

## 4. Exchange rates

`currencies.rate` is the current rate to the base currency, and
`exchange_rate_snapshots` is the append-only history of how it got there.

Rates are never used to compute a sale price. They exist for reporting and
for the Phase 4 invoice snapshot. A customer buying in EUR pays the EUR price
row, exactly as entered.

## 5. Storefront

The public catalog renders through `StorefrontRenderer` (ADR 0009), so it
stays themeable, indexable and replaceable.

- `GET /` — groups with their visible products
- `GET /products/{group:slug}` — one group
- `GET /products/{group:slug}/{product:slug}` — one product with its options
  and addons, priced in the visitor's currency

Only active, visible products in active groups appear. Hidden products remain
reachable by direct link, because that is what "hidden" means in every
hosting platform: orderable by someone who was sent the URL.

The theme foundation: a `themes/storefront/<name>` layout with a manifest, a
view namespace registered ahead of the core fallback, and the precedence
chain from ADR 0009 wired for real rather than described. Child themes and
branding settings stay in Phase 11.

## 6. Permissions added

Staff: `catalog.groups.view`, `catalog.groups.manage`,
`catalog.products.view`, `catalog.products.manage`,
`catalog.pricing.manage` (high risk — it changes what customers are
charged), `catalog.currencies.manage` (high risk).

## 7. Screens

**Admin**: group list and form, product list and form, a price matrix per
product (cycles down, currencies across), option groups and options, addons,
currency list and form.

**Storefront**: catalog index, group page, product page with a live price
that updates as options are chosen.

## 8. Testing

- Money: arithmetic, cross-currency refusal, allocation summing exactly,
  non-two-decimal currencies, and that no float appears in a monetary path.
- Billing cycle: period arithmetic and label translation.
- Organization isolation for every new table.
- A product is only sellable in a currency it has a price row for.
- Storefront shows active and visible items only; hidden ones resolve by
  direct link.
- Price changes are audited with the before and after amounts.
- Option price deltas compose correctly into a total.

## 9. Order of work

1. Money value object, currency handling and the cast.
2. Migrations, domain enums, models, factories.
3. Catalog application use cases.
4. Admin screens: groups, products, prices, options, addons, currencies.
5. Storefront catalog and the theme foundation.
6. ADRs, docs, result document.

Each step is a separate commit and leaves the suite green.
