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
| [ ] | `Admin/Customers/Users.vue` | list |  | 7 |  |  |  |
| [ ] | `Admin/Orders/Review.vue` | form | 1 |  |  | 1 |  |
| [ ] | `Admin/Resellers/Create.vue` | form | 2 |  |  |  |  |
| [ ] | `Admin/Resources/Adapters.vue` | list | 2 |  |  |  | yes |
| [ ] | `Admin/Resources/Explorer.vue` | list |  | 5 |  |  | yes |
| [ ] | `Admin/Roles/Form.vue` | form | 2 |  |  |  |  |
| [ ] | `Admin/Staff/Index.vue` | list |  | 6 |  |  |  |
| [ ] | `Admin/Support/Replies.vue` | list | 2 |  |  |  |  |
| [ ] | `Admin/Apps/Connect.vue` | form | 1 | 6 |  |  |  |
| [ ] | `Admin/Automation/Index.vue` | list | 1 | 8 |  |  |  |
| [ ] | `Admin/Billing/Transactions.vue` | list | 1 | 7 |  |  |  |
| [ ] | `Admin/Invoices/Index.vue` | list |  | 9 |  | 1 | yes |
| [ ] | `Admin/Resources/Telemetry.vue` | list | 1 | 5 |  |  | yes |
| [ ] | `Admin/Services/Addons.vue` | list |  | 8 |  | 1 |  |
| [ ] | `Admin/Services/Index.vue` | list |  | 8 |  | 1 |  |
| [ ] | `Admin/Automation/Dunning.vue` | form | 1 |  |  |  |  |
| [ ] | `Admin/Catalog/Groups/Form.vue` | form | 1 |  |  |  |  |
| [ ] | `Admin/Catalog/Products/Pricing.vue` | form | 1 |  |  |  |  |
| [ ] | `Admin/Content/Announcements.vue` | form | 1 |  |  |  |  |
| [ ] | `Admin/Notifications/Templates.vue` | list | 1 |  |  |  |  |
| [ ] | `Admin/Search/Index.vue` | list | 1 |  |  |  |  |
| [ ] | `Admin/Todo/Index.vue` | list | 1 |  |  |  |  |
| [ ] | `Admin/Api/Activity.vue` | list |  | 6 |  |  |  |
| [ ] | `Admin/Billing/GatewayLog.vue` | form |  | 6 |  |  |  |
| [ ] | `Admin/Cancellations/Index.vue` | list |  | 8 |  |  |  |
| [x] | `Admin/Catalog/Addons/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Currencies/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Groups/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Options/Index.vue` | list |  |  |  |  | yes |
| [x] | `Admin/Catalog/Products/Index.vue` | list |  |  |  |  | yes |
| [ ] | `Admin/Domains/Index.vue` | list |  | 9 |  |  |  |
| [ ] | `Admin/Notifications/Log.vue` | list |  | 5 |  |  |  |
| [ ] | `Admin/Operations/Index.vue` | list |  | 6 |  |  |  |
| [ ] | `Admin/Orders/Index.vue` | list |  | 9 |  |  |  |
| [ ] | `Admin/Organizations/Index.vue` | list |  | 6 |  |  |  |
| [ ] | `Admin/Promotions/Index.vue` | list |  | 6 |  |  |  |
| [ ] | `Admin/Resellers/Index.vue` | list |  | 6 |  |  |  |
| [ ] | `Admin/Resellers/Report.vue` | detail |  | 7 |  |  |  |
| [ ] | `Admin/Roles/Index.vue` | list |  | 4 |  |  |  |
| [ ] | `Admin/Support/Index.vue` | list |  | 6 |  |  |  |
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
| [ ] | `Client/Contacts.vue` | list | 2 |  |  |  |  |
| [x] | `Client/Dashboard.vue` | overview |  |  |  |  | yes |
| [ ] | `Client/Developer/Tokens.vue` | list | 2 |  |  |  | yes |
| [ ] | `Client/Developer/Webhooks.vue` | list | 3 | 5 |  |  | yes |
| [x] | `Client/Domains/Index.vue` | list |  |  |  |  | yes |
| [x] | `Client/Domains/Show.vue` | detail |  |  |  |  | yes |
| [ ] | `Client/Notifications/Index.vue` | list | 1 |  |  |  | yes |
| [ ] | `Client/Orders/Index.vue` | list |  | 5 |  |  | yes |
| [ ] | `Client/Orders/Show.vue` | detail | 3 |  |  |  | yes |
| [ ] | `Client/Profile.vue` | form | 2 |  |  |  |  |
| [x] | `Client/Services/Index.vue` | list |  |  |  |  | yes |
| [x] | `Client/Services/Show.vue` | detail | 1 |  |  |  | yes |
| [ ] | `Client/Support/Create.vue` | form | 1 |  |  |  | yes |
| [ ] | `Client/Support/Index.vue` | list |  | 4 |  |  | yes |
| [ ] | `Client/Support/Show.vue` | detail | 1 |  |  |  | yes |

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
