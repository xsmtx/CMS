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
| [ ] | `Admin/Orders/Create.vue` | form | 6 |  |  |  |  |
| [ ] | `Admin/Customers/Create.vue` | form | 7 |  |  |  |  |
| [x] | `Admin/Domains/Show.vue` | detail |  |  |  |  | yes |
| [x] | `Admin/Billing/AddTransaction.vue` | form |  |  |  |  | yes |
| [ ] | `Admin/Resellers/Show.vue` | detail | 5 | 16 |  |  |  |
| [ ] | `Admin/Licence/Index.vue` | list | 4 | 4 |  |  |  |
| [ ] | `Admin/Billing/Settings.vue` | form | 4 |  |  |  | yes |
| [ ] | `Admin/Content/Articles.vue` | form | 2 | 6 |  |  |  |
| [ ] | `Admin/Customers/Form.vue` | form | 4 |  |  |  |  |
| [ ] | `Admin/Support/Show.vue` | detail | 4 |  |  |  |  |
| [ ] | `Admin/Tax/Index.vue` | list | 3 | 9 |  |  | yes |
| [ ] | `Admin/Catalog/Options/Form.vue` | form | 2 |  |  | 1 |  |
| [ ] | `Admin/Contacts/Form.vue` | form | 3 |  |  |  |  |
| [ ] | `Admin/Health/Index.vue` | list | 3 |  |  |  |  |
| [ ] | `Admin/Modules/Index.vue` | list | 3 |  |  |  |  |
| [ ] | `Admin/Promotions/Form.vue` | form | 3 |  |  |  |  |
| [ ] | `Admin/Staff/Form.vue` | form | 3 |  |  |  |  |
| [ ] | `Admin/Support/Overview.vue` | overview | 3 |  |  |  |  |
| [ ] | `Admin/Import/Index.vue` | list | 2 | 8 |  |  |  |
| [ ] | `Admin/Import/Show.vue` | detail | 2 | 9 |  |  |  |
| [ ] | `Admin/Infrastructure/Index.vue` | list | 2 | 12 |  |  |  |
| [ ] | `Admin/Tlds/Index.vue` | list | 2 | 6 |  |  |  |
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
| [ ] | `Admin/Catalog/Addons/Index.vue` | list |  | 4 |  |  |  |
| [ ] | `Admin/Catalog/Currencies/Index.vue` | list |  | 4 |  |  |  |
| [ ] | `Admin/Catalog/Groups/Index.vue` | list |  | 4 |  |  |  |
| [ ] | `Admin/Catalog/Options/Index.vue` | list |  | 4 |  |  |  |
| [ ] | `Admin/Catalog/Products/Index.vue` | list |  | 5 |  |  |  |
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
| [ ] | `Client/Billing/BillingTabs.vue` | form |  |  |  |  | yes |
| [ ] | `Client/Billing/Details.vue` | form | 2 |  |  |  | yes |
| [ ] | `Client/Billing/Invoice.vue` | detail | 5 |  |  | 1 | yes |
| [ ] | `Client/Billing/Invoices.vue` | list |  | 6 |  | 1 | yes |
| [ ] | `Client/Billing/Transactions.vue` | list |  | 5 |  | 1 | yes |
| [ ] | `Client/Contacts.vue` | list | 2 |  |  |  |  |
| [ ] | `Client/Dashboard.vue` | overview | 4 |  |  | 1 | yes |
| [ ] | `Client/Developer/Tokens.vue` | list | 2 |  |  |  | yes |
| [ ] | `Client/Developer/Webhooks.vue` | list | 3 | 5 |  |  | yes |
| [ ] | `Client/Domains/Index.vue` | list | 1 |  |  |  | yes |
| [ ] | `Client/Domains/Show.vue` | detail | 4 |  |  |  | yes |
| [ ] | `Client/Notifications/Index.vue` | list | 1 |  |  |  | yes |
| [ ] | `Client/Orders/Index.vue` | list |  | 5 |  |  | yes |
| [ ] | `Client/Orders/Show.vue` | detail | 3 |  |  |  | yes |
| [ ] | `Client/Profile.vue` | form | 2 |  |  |  |  |
| [ ] | `Client/Services/Index.vue` | list | 1 |  |  |  | yes |
| [ ] | `Client/Services/Show.vue` | detail | 3 |  |  |  | yes |
| [ ] | `Client/Support/Create.vue` | form | 1 |  |  |  | yes |
| [ ] | `Client/Support/Index.vue` | list |  | 4 |  |  | yes |
| [ ] | `Client/Support/Show.vue` | detail | 1 |  |  |  | yes |

## Account & auth

| Done | Page | Type | cards | padded td | solid danger | arbitrary type | i18n |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [ ] | `Security/Index.vue` | list | 4 |  |  |  |  |
| [ ] | `Security/TwoFactorSetup.vue` | form | 1 |  |  |  |  |
| [ ] | `Auth/ConfirmPassword.vue` | auth |  |  |  |  | yes |
| [ ] | `Auth/ForgotPassword.vue` | auth |  |  |  |  |  |
| [ ] | `Auth/Login.vue` | auth |  |  |  |  |  |
| [ ] | `Auth/ResetPassword.vue` | auth |  |  |  |  |  |
| [ ] | `Auth/TwoFactorChallenge.vue` | auth |  |  |  |  |  |
