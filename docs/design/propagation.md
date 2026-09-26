# Design propagation tracker

Every Inertia page and how far it is from the enterprise design system
(ADR 0048). Work top to bottom inside each section; tick a page only after
it passes the gates **and** `visual-quality-review` in the browser. How to
convert a page: `.claude/skills/frontend-architecture/page-recipes.md`.

Columns: **cards** = `<AppCard>` used (most should become `DetailSection`);
**padded td** = cells with hand padding (ignore density); **solid danger** =
`variant="danger"` buttons in the page body (move to a Danger Zone +
`AppConfirm`); **arbitrary type** = `text-[…]`/`text-lg…`; **i18n** = page
already reads translations (otherwise strings are hard-coded English).

Already done everywhere: type scale, radius utilities, status via
`statusTone()` + `AppStatus`, confirmations on one-click destructive actions.

Numbers generated 2026-09-24; re-count with the grep in
`visual-quality-review` after editing a page.

**All 105 pages are converted** (2026-09-25). The list stays here as the
record of what was covered and as the place a new page joins: a screen added
after this date gets a row, and it is ticked the same way — the gates, then
the browser.

## 2026-09-26 — the language changed under all of them

Every page in this file was converted to the enterprise design system and
then **re-skinned to `DESIGN.md`** (`Apple-design-analysis`) at the token
layer: palette, type scale, radii, spacing ladder, the black `global-nav`,
the frosted floating layer and the one product shadow. No page was converted
again — that is what ADR 0040 bought, and it is the evidence for it.

Three components changed shape rather than colour: `AppButton` (primary and
danger are pills, utility keeps the 8px corner), `AppBadge` (12px, and the
brand tone shifts by `--badge-ink-shift`), and the four floating layers
(`.floating` instead of a shadow that no longer exists).

The storefront's fourteen Blade views moved to the marketing register —
full-bleed tiles alternating canvas and parchment, a hero that fills a
viewport, `store-utility-card` for products.

Verified by `tools/design-review.mjs`: 168 renders, both appearances, axe on
each. See `CLAUDE.md` for what it found.


## Reference screens (done)

| Done | Page | Type | cards | padded td | solid danger | arbitrary type | i18n |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [x] | `Admin/Customers/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Customers/Show.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Dashboard.vue` | overview |  |  |  |  | yes |

## Admin — highest priority first

| Done | Page | Type | cards | padded td | solid danger | arbitrary type | i18n |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [x] | `Admin/Invoices/Show.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Orders/Show.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Services/Show.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Reports/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Support/Create.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Settings/Index.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Orders/Create.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Customers/Create.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Domains/Show.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Billing/AddTransaction.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Resellers/Show.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Licence/Index.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Billing/Settings.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Content/Articles.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Customers/Form.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Support/Show.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Tax/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Options/Form.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Contacts/Form.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Health/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Modules/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Promotions/Form.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Staff/Form.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Support/Overview.vue` | overview |  |  |  |  | yes |
| [x] | `Admin/Import/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Import/Show.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Infrastructure/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Tlds/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Addons/Form.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Catalog/Currencies/Form.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Catalog/Products/Form.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Customers/Users.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Orders/Review.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Resellers/Create.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Resources/Adapters.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Resources/Explorer.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Roles/Form.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Staff/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Support/Replies.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Apps/Connect.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Automation/Index.vue` | list | 1 |  |  |  | yes |
| [x] | `Admin/Billing/Transactions.vue` | list | 1 |  |  |  | yes |
| [x] | `Admin/Invoices/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Resources/Telemetry.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Services/Addons.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Services/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Automation/Dunning.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Catalog/Groups/Form.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Catalog/Products/Pricing.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Content/Announcements.vue` | form |  |  |  |  | yes |
| [x] | `Admin/Notifications/Templates.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Search/Index.vue` | list | 1 |  |  |  | yes |
| [x] | `Admin/Todo/Index.vue` | list | 1 |  |  |  | yes |
| [x] | `Admin/Api/Activity.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Billing/GatewayLog.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Cancellations/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Addons/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Currencies/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Groups/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Options/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Products/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Domains/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Notifications/Log.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Operations/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Orders/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Organizations/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Promotions/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Resellers/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Resellers/Report.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Roles/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Support/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Apps/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Apps/Marketplace.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Modules/ModuleSettings.vue` | form |  |  |  |  | yes |

## Client area (Horizon-leaning; same rules, comfortable density)

| Done | Page | Type | cards | padded td | solid danger | arbitrary type | i18n |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [x] | `Client/Billing/BillingTabs.vue` | form |  |  |  |  | yes |
| [x] | `Client/Billing/Details.vue` | form |  |  |  |  | yes |
| [x] | `Client/Billing/Invoice.vue` | detail | 1 |  |  |  | yes |
| [x] | `Client/Billing/Invoices.vue` | list |  |  |  |  | yes |
| [x] | `Client/Billing/Transactions.vue` | list |  |  |  |  | yes |
| [x] | `Client/Contacts.vue` | list |  |  |  |  | yes |
| [x] | `Client/Dashboard.vue` | overview |  |  |  |  | yes |
| [x] | `Client/Developer/Tokens.vue` | list |  |  |  |  | yes |
| [x] | `Client/Developer/Webhooks.vue` | list |  |  |  |  | yes |
| [x] | `Client/Domains/Index.vue` | list |  |  |  |  | yes |
| [x] | `Client/Domains/Show.vue` | detail |  |  |  |  | yes |
| [x] | `Client/Notifications/Index.vue` | list |  |  |  |  | yes |
| [x] | `Client/Orders/Index.vue` | list |  |  |  |  | yes |
| [x] | `Client/Orders/Show.vue` | detail | 1 |  |  |  | yes |
| [x] | `Client/Profile.vue` | form |  |  |  |  | yes |
| [x] | `Client/Services/Index.vue` | list |  |  |  |  | yes |
| [x] | `Client/Services/Show.vue` | detail | 1 |  |  |  | yes |
| [x] | `Client/Support/Create.vue` | form |  |  |  |  | yes |
| [x] | `Client/Support/Index.vue` | list |  |  |  |  | yes |
| [x] | `Client/Support/Show.vue` | detail |  |  |  |  | yes |

## Account & auth

| Done | Page | Type | cards | padded td | solid danger | arbitrary type | i18n |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [x] | `Security/Index.vue` | list |  |  |  |  | yes |
| [x] | `Security/TwoFactorSetup.vue` | form |  |  |  |  | yes |
| [x] | `Auth/ConfirmPassword.vue` | auth |  |  |  |  | yes |
| [x] | `Auth/ForgotPassword.vue` | auth |  |  |  |  | yes |
| [x] | `Auth/Login.vue` | auth |  |  |  |  | yes |
| [x] | `Auth/Register.vue` | auth |  |  |  |  | yes |
| [x] | `Auth/ResetPassword.vue` | auth |  |  |  |  | yes |
| [x] | `Auth/TwoFactorChallenge.vue` | auth |  |  |  |  | yes |
