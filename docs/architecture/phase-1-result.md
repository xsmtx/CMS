# Phase 1 — Identity + CRM Result

Status: complete
Date: 2026-09-23
Plan: `phase-1-plan.md`
Next phase: Phase 2 (Catalog + Storefront) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **219 passed, 752 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 3 passed |
| Build | `vite build` | pass |
| Migrations | 11 migrations on MariaDB 11.8 | clean |

Phase 0 finished at 111 tests; this phase adds 108. Feature tests run against
a real MariaDB, never SQLite.

The suite outgrew PHP's 128 MB default, so `composer test` and the CI step now
set `memory_limit=512M` explicitly rather than depending on whatever the host
happens to configure.

## 2. Two problems the tests found

**Route-model binding was unscoped.** Appended middleware runs *after*
`SubstituteBindings`, so the organization boundary did not exist when a route
parameter was resolved. A reseller could reach another reseller's record by
id, with the policy as the only thing standing between them. The boundary is
now forced ahead of binding via the middleware priority list, so the scope
applies to the lookup itself and the record does not resolve at all. Found by
a test that expected 404 and got 403.

**Domain refusals rendered as 500 in the browser.** `PlatformException`
carried an error code for the API but no HTTP status, so a refusal that
answered 403 on `/api/*` answered 500 on an HTML request. It now implements
`HttpExceptionInterface`, which is what the shared error contract was
supposed to guarantee in the first place.

## 3. What was built

### Identity

- `StaffUser` and `Contact`, two tables and two guards (ADR 0016). The
  `users` table was renamed rather than dual-written; Phase 0 had no
  production data.
- Sign-in, sign-out and password reset for both guards, built on Laravel's
  native `Auth` rather than Fortify (ADR 0017).
- One message for every credential failure, with the real reason in
  `login_histories`; a hash comparison still runs on a miss so response time
  does not answer what the message refuses to.
- Two throttle buckets: per identity and per address.
- TOTP two-factor with a three-step enrolment, hashed single-use recovery
  codes, a server-rendered QR code, and a challenge that holds a verified
  sign-in without granting a session.
- `authenticated_sessions` mirrors live sessions out of Redis so a user can
  see and revoke their other devices; revocation goes through the session
  driver's own handler.
- `identity:create-owner` creates the first staff account, so a fresh
  installation is reachable without seeding by hand.

### Access

- Staff administration with a search, role assignment filtered to staff scope
  by re-reading from the database, and a two-factor recovery path for someone
  who has lost their authenticator.
- Two deletions refused outright: your own account, and the last super
  administrator.
- Role administration with a permission matrix built from the registry, so a
  capability added in code appears as soon as it is synced with its group and
  risk flag intact. A system role's name is editable; its slug and scope are
  not.
- Policies for every model, each asking the boundary question before the
  permission question.
- A new `portal-member` system role: portal access now implies exactly one
  role, and the primary contact is the only one who can change who else
  reaches the account.

### CRM

- Customers, created together with their organization in one transaction, and
  hanging off the creator's organization.
- Contacts, with portal access that never sets a password and withdraws the
  password along with the access.
- Addresses, tags, custom field definitions and values, and notes that are
  internal unless deliberately marked visible.
- A customer status state machine; an invalid transition answers 409 with
  `invalid_state_transition`.

### Privacy

- Subject-access export as JSON, omitting password hashes, two-factor
  secrets and internal staff notes.
- Erasure that overwrites rather than deletes, so invoices keep referring to
  something, and that leaves the audit trail alone: that trail is the
  platform's account of its own actions, and destroying it would remove the
  evidence the erasure was authorised.

### Impersonation

Boundary-checked strictly below, reason required and stored, start and end
both audited, one guard authenticated at a time, security actions refused
while active, an undismissable banner, and rate limiting (ADR 0018).

### Client area

Sign-in, password reset, two-factor, profile, contacts and the shared
security screen.

## 4. Files

New or substantially changed, by area:

```text
app/Domain/Identity/      AccountStatus, Guard, LoginFailureReason,
                          Contracts/PlatformAccount
app/Domain/Crm/           CustomerStatus, AddressType, CustomFieldType,
                          CustomFieldEntity
app/Application/Identity/ AuthenticateUser, LoginAttempt, LoginThrottle,
                          RecordLoginAttempt, SessionRegistry,
                          TwoFactorAuthenticator, Impersonator,
                          Create/Update/DeleteStaffMember, StaffAttributes,
                          RoleIds
app/Application/Access/   Create/Update/DeleteRole, RoleAttributes,
                          PermissionIds
app/Application/Crm/      Create/UpdateCustomer, CustomerAttributes,
                          SaveContact, ContactAttributes, DeleteContact,
                          SaveCustomFieldValues, ExportCustomerData,
                          AnonymizeCustomer, Exceptions/
app/Infrastructure/       Identity/{Models,Concerns,Contracts,Notifications},
                          Crm/{Models,Concerns}
app/Http/                 Controllers/{Auth,Admin,Client}, Requests/,
                          Middleware/{TrackAuthenticatedSession,
                          BlockDuringImpersonation}
app/Policies/             StaffUser, Role, Customer, Contact, Note, Tag,
                          CustomFieldDefinition, Organization,
                          Concerns/ChecksOrganizationBoundary
app/Support/Identity/     CurrentActor
app/Console/Commands/     CreateOwnerCommand
database/migrations/      identity tables, CRM tables
database/factories/       StaffUser, Contact, Customer, Address, Tag, Note,
                          CustomField*, LoginHistory, AuthenticatedSession,
                          Impersonation
routes/                   auth.php, admin.php, client.php
resources/js/             Pages/{Auth,Security,Admin/*,Client/*},
                          Components/{AppInput,AppSelect,AppTextarea,
                          AppCheckbox,AppAlert,AppTable,AppPagination,
                          CustomFieldInput}, Layouts/AuthLayout
lang/{en,tr}/             identity.php, crm.php
tests/Feature/            Authentication, TwoFactor, SessionManagement,
                          StaffAdministration, RoleAdministration,
                          CustomerManagement, Impersonation, ClientArea
docs/adr/                 0016, 0017, 0018
```

## 5. Not done, and why

| Item | Detail |
| --- | --- |
| **Custom field and tag admin screens** | The models, validation, storage and rendering all work, and custom fields appear on the customer form. There is no screen for an operator to *define* a field or create a tag; both have to be seeded. This is the one item from the plan that did not land. |
| **Address editing UI** | Addresses are stored, exported, erased and shown on the customer page. There is no form to add or edit one yet. |
| **Note composition UI** | Notes are listed, exported and erased; there is no compose box. |
| **Email verification** | Contacts and staff carry `email_verified_at`; nothing enforces it. It belongs with the storefront sign-up flow in Phase 2. |
| **Customer credit** | Deliberately deferred to Phase 4. A balance with no append-only ledger behind it would violate the financial-records rule. |
| **Customer numbers** | Sequential human-readable numbering arrives with invoice numbering in Phase 4, where the sequence machinery is built properly. |

## 6. Carried risks

| Item | Detail |
| --- | --- |
| **Password reset mail is untested end to end** | The brokers, tokens and notifications are wired and unit-covered, but no mail has been sent through a real SMTP server. Mailpit is in the Compose stack for exactly this. |
| **Session revocation under Redis is untested against a live driver** | The registry is tested directly and the handler call is real, but the suite runs with the array session driver. |
| **Roles remain installation-global** | Resellers share the role catalogue. Correct for now; revisit in Phase 13. |
| **No architecture test yet forces `BelongsToOrganization`** | A future owned model that omits the trait is silently unscoped. Now that several owned contexts exist, this rule is worth writing. |
| **`portal_access` roles are coarse** | Account owner or portal member, nothing between. Finer customer permissions can be added to the registry without schema change. |

## 7. Exact next recommended task

**Phase 2 — Catalog + Storefront**, first slice: product groups, products and
price books.

1. `product_groups`, `products`, `product_prices` with billing cycles
   (one-time, monthly, quarterly, semiannual, annual, biennial, triennial),
   all organization-owned.
2. Introduce the `Money` value object and `brick/money` (ADR 0014). Prices are
   integer minor units plus an ISO currency from the first migration; no
   monetary column is ever a float.
3. Configurable options and option groups, and addons.
4. Currencies and exchange-rate snapshots, so a price can be quoted in more
   than one currency without rewriting history.
5. Admin catalog screens: groups, products, price matrix, options, addons.
6. The storefront catalog rendered through `StorefrontRenderer`, plus the
   theme foundation that Phase 11 builds on.

Definition of Done for that slice: organization-isolation tests for every new
table, money arithmetic covered including allocation and rounding, policies
for every screen, translations in both locales, and all gates green.

**Do not begin Phase 2 without being asked.**
