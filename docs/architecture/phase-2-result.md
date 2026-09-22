# Phase 2 — Catalog + Storefront Result

Status: complete
Date: 2026-09-24
Plan: `phase-2-plan.md`
Next phase: Phase 3 (Ordering + Provisioning) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **329 passed, 1034 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 17 passed |
| Build | `vite build` | pass |
| Migrations | 13 migrations on MariaDB 11.8 | clean |

Phase 1 finished at 219 tests; this phase adds 110. Feature tests run against
a real MariaDB, never SQLite. The front-end suite grew from 3 to 17: the
money parsing and the price payload now have tests of their own, for the
reason in §2.

## 2. Four problems found, three of them by looking

**The price form submitted keys the server rejected.** The matrix screen sent
`billingCycle`/`recurringMinor`; the request validates
`billing_cycle`/`recurring_minor`. Validation failed on nested keys
(`prices.0.billing_cycle`), the screen only looked at `errors.prices`, and so
saving prices did nothing at all and said nothing about it. The feature tests
passed because they posted the server's own shape.

Caught by walking the flow in a browser, which is the only place a
client/server contract mismatch shows up. Fixed three ways: the mapping moved
into one tested function (`toPricePayload`), the screens now surface any
error whose key touches `prices`, and the mapping has Vitest coverage that
pins the key names.

**The admin sidebar was empty for a super administrator.** The role bypasses
the permission check rather than holding grants, so `effectivePermissions()`
— which the navigation reads to decide what to show — returned an empty list
for the account that can do everything. This had been true since Phase 1 and
affected every admin screen, not just the catalog. It now returns every
permission in the subject's scope, which is the same answer the gate gives.

**The storefront boundary was a subtree.** The organization boundary answers
"what may this actor reach", which for a provider includes every reseller
under it. A storefront asks "what does this brand sell". Left alone, the
public catalog would have listed every reseller's products. Now scoped to
exactly one organization ([ADR 0020](../adr/0020-storefront-organization-boundary.md)),
with a test that puts a reseller product in the database and asserts it is
neither listed nor reachable by slug.

**Allocation lost a unit on negative amounts.** `Money::allocate()` used
`intdiv`, which truncates toward zero, so splitting −100 across three parts
gave −99. Invoices reconcile; credit notes have to as well. Fixed and tested.

## 3. What was built

### Money

- `Money` and `Currency` value objects over `brick/money`
  ([ADR 0014](../adr/0014-money-representation.md)). Integer minor units plus
  an ISO 4217 code, a decimal-string constructor, and no `toFloat()` — a test
  asserts the absence, because the invariant is what matters, not the
  implementation.
- Arithmetic refuses to cross currencies. Largest-remainder allocation makes
  the parts sum exactly to the whole in both directions.
- Currencies with other exponents are carried, not assumed: JPY has none,
  KWD has three, and the admin form takes the exponent from ISO rather than
  from the operator.
- `MoneyCast` stores an amount as a `bigint` column plus a currency column
  and writes both together, so a row can never be half-updated into meaning
  something else.

### Catalog

- Product groups, products, configurable option groups and their choices,
  and addons — all organization-owned, all slugged, all with an
  active/hidden/retired status where hidden means "off the menu, reachable
  by direct link".
- Price matrices for products, options and addons, keyed by
  (item, billing cycle, currency), with a unique index behind it
  ([ADR 0019](../adr/0019-price-matrix.md)). **A missing row means not sold;
  zero means free.**
- Option prices are signed, so "no control panel" is priced as a reduction.
- Billing cycles from one-time to triennial, with `nextDueDate()` using
  no-overflow month arithmetic: 31 January plus a month is 28 February, not
  3 March, which would bill a customer early for the life of the
  subscription.
- Stock as a nullable integer: null is unlimited, zero is sold out, and the
  two are distinguishable.
- Currencies and append-only exchange-rate snapshots. Rates never touch a
  sale price; they exist for reporting and for the invoice snapshot Phase 4
  takes.

### Admin

- Screens for groups, products, the price matrix, option groups with their
  choices, addons and currencies.
- The price grid is the centrepiece: one tab per currency, one row per
  cycle, a tick that decides whether the cell exists, and amounts held as
  minor units in the browser as well as on the server.
- Pricing answers to its own permission (`catalog.pricing.manage`) and
  writes its own audit action (`catalog.pricing.updated`) carrying the
  before and after of the whole grid. An operator may be trusted to write a
  description without being trusted to change what a customer is charged.
- Flash messages moved into `AdminLayout`: an action that redirects has no
  page left to report on, and three screens were reporting nothing.

### Storefront

- `/store` lists the groups and products sold in the visitor's currency;
  `/store/{slug}` shows one product with its cycles, options and addons.
- Currency selection is a POST, remembered in the session and validated
  against what the installation actually trades in.
- A product appears only where it has a price row in that currency. Nothing
  is converted — a price that changes between the listing and the cart is a
  price the customer will notice, and rightly not trust.
- Rendered through `StorefrontRenderer` as Blade, extending a shared layout
  themes can replace. The home page now has two states: nothing for sale
  (with `noindex` and the setup steps) and a catalog to point at.
- The order button says ordering opens soon rather than pretending to work.

## 4. Files

New or substantially changed, by area:

```text
app/Domain/Shared/        Money, Currency, Exceptions/{CurrencyMismatch,
                          UnknownCurrency}
app/Domain/Catalog/       BillingCycle, ProductType, OptionType,
                          CatalogStatus
app/Application/Catalog/  SaveProductGroup, DeleteProductGroup, SaveProduct,
                          DeleteProduct, SavePriceMatrix, PriceMatrixEntry,
                          SaveOptionGroup, DeleteOptionGroup, SaveAddon,
                          DeleteAddon, SaveCurrency, DeleteCurrency,
                          StorefrontCatalog, *Attributes, Exceptions/
app/Infrastructure/       Catalog/{Models,Concerns/HasPrices},
                          Shared/{Models/CurrencyRecord,
                          Models/ExchangeRateSnapshot, Casts/MoneyCast}
app/Http/                 Controllers/Admin/{ProductGroup,Product,
                          ProductPricing,OptionGroup,Addon,Currency},
                          Controllers/StorefrontCatalogController,
                          Requests/Catalog/, Middleware/
                          ResolveStorefrontOrganization
app/Policies/             ProductGroup, Product, CurrencyRecord
app/Support/Catalog/      StorefrontCurrency
database/migrations/      currency tables, catalog tables
database/factories/       ProductGroup, Product, ProductPrice, OptionGroup,
                          Option, OptionPrice, Addon, AddonPrice,
                          CurrencyRecord, ExchangeRateSnapshot
resources/js/             Pages/Admin/Catalog/*, Components/{MoneyInput,
                          PriceMatrix}, types/catalog.ts
resources/views/          storefront/{layout,home,catalog,product}
lang/{en,tr}/             catalog.php, storefront.php
tests/                    Unit/Shared/MoneyTest, Unit/Catalog/
                          BillingCycleTest, Unit/TranslationTest,
                          Feature/{CatalogModel,Currency,CatalogUseCase,
                          CatalogAdministration,StorefrontCatalog,
                          OwnedModels}
docs/adr/                 0019, 0020
```

## 5. Carried items now closed

| Item | Detail |
| --- | --- |
| **Architecture test forcing the ownership scope** | Carried from Phase 1. `OwnedModelsTest` walks every infrastructure model, finds the ones whose table has an `organization_id`, and asserts each one is bounded — and, unless its organization is nullable by design, stamped on write. |
| **Turkish translations folded to ASCII** | Phase 0 and 1 shipped `Musteri` where `Müşteri` belongs, an artefact of an encoding problem while writing the files. All seven files are rewritten with their diacritics, and a test now asserts both locales carry the same keys and that the Turkish files stay UTF-8. |

## 6. Not done, and why

| Item | Detail |
| --- | --- |
| **Ordering, cart and checkout** | Phase 3, by design. The storefront describes and prices; it does not sell yet. |
| **Custom field and tag admin screens** | Still carried from Phase 1. Both still have to be seeded. |
| **Address and note composition UI** | Still carried from Phase 1. |
| **Email verification enforcement** | Phase 1 deferred it to "the storefront sign-up flow in Phase 2". There is no sign-up flow until ordering exists, so it moves to Phase 3 with the flow it belongs to. |
| **Drag-to-reorder** | Groups, products, options and addons carry a `position` an operator types. A drag handle is worth having once a catalog is large enough to make typing positions tiresome. |
| **Bulk price changes** | "Raise every annual price by 5%" is a real operator task and not yet possible. It wants its own screen, its own confirmation and its own audit entry rather than a loop over the existing one. |
| **Per-reseller catalogs on their own domains** | The storefront serves the provider's catalog. Hostname resolution is Phase 11's white-labelling work ([ADR 0020](../adr/0020-storefront-organization-boundary.md)). |

## 7. Carried risks

| Item | Detail |
| --- | --- |
| **Deleting a product deletes its prices** | Nothing references a product yet, so the cascade is safe today. Once services and invoices exist, deletion has to be refused for anything ever ordered; the use case is commented to that effect and Phase 3 adds the check. |
| **Option keys are editable after creation** | An order line will refer to an option by key. Once Phase 3 stores those lines, changing a key retrospectively renames something a customer bought, and the field should lock. |
| **No catalog-wide currency guard** | An operator can deactivate a currency that products are still priced in. Deletion is refused, deactivation is not — deliberately, since taking a currency off sale without destroying its prices is the point, but a warning on the screen would be better than silence. |
| **The starting price is computed per request** | `startingPrice()` reduces every cycle to a monthly equivalent for each product on the listing. Fine at catalog sizes; it wants caching before a storefront with hundreds of plans. |
| **Exchange rates are entered by hand** | There is no provider feed. The snapshot table and the `source` column are shaped for one, and a scheduled fetch is a Phase 10 concern. |

## 8. Exact next recommended task

**Phase 3 — Ordering + Provisioning**, first slice: the cart and order
capture.

1. `orders` and `order_items`, with the price, currency and cycle
   **copied onto the line** rather than referenced, so a later catalog edit
   never rewrites what someone agreed to pay.
2. A cart that can hold a product, its option choices and its addons, priced
   by composing the matrix the way the storefront already displays it.
3. Checkout collecting the domain where the product type requires one, and
   the account details for a visitor who has no account yet — which is where
   email verification enforcement finally lands.
4. The order state machine, with every transition audited.
5. Provisioning contracts behind a versioned interface, with a null
   provider, so an order can complete without a panel attached.
6. Idempotent provisioning jobs on Horizon, keyed so a retry never creates a
   second account.
