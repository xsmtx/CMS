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
| [ ] | `Admin/Catalog/Addons/Form.vue` | form | 2 |  |  |  |  |
| [ ] | `Admin/Catalog/Currencies/Form.vue` | form | 1 |  |  | 1 |  |
| [ ] | `Admin/Catalog/Products/Form.vue` | form | 2 |  |  |  |  |
| [x] | `Admin/Customers/Users.vue` | list |  |  |  |  | yes |
| [ ] | `Admin/Orders/Review.vue` | form | 1 |  |  | 1 |  |
| [ ] | `Admin/Resellers/Create.vue` | form | 2 |  |  |  |  |
| [ ] | `Admin/Resources/Adapters.vue` | list | 2 |  |  |  | yes |
| [ ] | `Admin/Resources/Explorer.vue` | list |  | 5 |  |  | yes |
| [ ] | `Admin/Roles/Form.vue` | form | 2 |  |  |  |  |
| [x] | `Admin/Staff/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Support/Replies.vue` | list |  |  |  |  | yes |
| [ ] | `Admin/Apps/Connect.vue` | form | 1 | 6 |  |  |  |
| [x] | `Admin/Automation/Index.vue` | list | 1 |  |  |  | yes |
| [x] | `Admin/Billing/Transactions.vue` | list | 1 |  |  |  | yes |
| [x] | `Admin/Invoices/Index.vue` | list |  |  |  |  | yes |
| [ ] | `Admin/Resources/Telemetry.vue` | list | 1 | 5 |  |  | yes |
| [x] | `Admin/Services/Addons.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Services/Index.vue` | list |  |  |  |  | yes |
| [ ] | `Admin/Automation/Dunning.vue` | form | 1 |  |  |  |  |
| [ ] | `Admin/Catalog/Groups/Form.vue` | form | 1 |  |  |  |  |
| [ ] | `Admin/Catalog/Products/Pricing.vue` | form | 1 |  |  |  |  |
| [ ] | `Admin/Content/Announcements.vue` | form | 1 |  |  |  |  |
| [ ] | `Admin/Notifications/Templates.vue` | list | 1 |  |  |  |  |
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
| [ ] | `Admin/Apps/Index.vue` | list |  |  |  |  | yes |
| [ ] | `Admin/Apps/Marketplace.vue` | list |  |  |  |  | yes |
| [ ] | `Admin/Modules/ModuleSettings.vue` | form |  |  |  |  |  |

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
