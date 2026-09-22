# Phase 1 — Identity + CRM Plan

Status: approved for implementation
Date: 2026-09-22
Scope: V2 roadmap Phase 1 — customers, contacts, staff, organizations UI,
permissions UI, 2FA, sessions, profile/security, impersonation with audit.
Phase 2 (Catalog + Storefront) is out of scope.

---

## 1. Starting point

Phase 0 left a single placeholder authenticatable,
`App\Infrastructure\Identity\Models\User`, documented as "Phase 1 splits this".
It is staff-scoped, organization-owned, and carries `HasRoles`,
`HasApiTokens` and `BelongsToOrganization`. The `organizations` hierarchy, the
permission registry, the audit trail and the error envelope are already in
place and are the substrate this phase builds on.

Nothing in Phase 0 has production data, so the `users` table is renamed rather
than dual-written.

## 2. Identity model: two authenticatables, two guards

| Model | Table | Guard | Role scope | Who |
| --- | --- | --- | --- | --- |
| `StaffUser` | `staff_users` | `staff` | Staff | Provider and reseller employees |
| `Contact` | `contacts` | `client` | Customer | People at a customer organization |

Two tables rather than one with a `type` column, because:

- **Separate session cookies.** A staff session can never be mistaken for a
  customer session by a bug in a guard resolution. This is the reason that
  matters; the rest are consequences.
- **Different columns.** Contacts carry `customer_id`, communication
  preferences and a primary-contact flag. Staff carry none of those.
- **Different role scopes**, enforced at the model level rather than by a
  runtime check that someone can forget.
- **Different password brokers**, so a staff reset token cannot be redeemed on
  the client area.

Both models share the same concerns: `BelongsToOrganization`, `HasRoles`,
`HasUlids`, `HasApiTokens`, and a new `Authenticates` concern carrying 2FA,
login history and session bookkeeping.

## 3. CRM model

```text
Organization (boundary, Phase 0)
  └─ Customer            commercial profile, 1:1 with a customer organization
       ├─ Contact        person; may or may not have portal access
       ├─ Address        billing / technical / legal, polymorphic
       ├─ Tag            via taggables, organization-scoped vocabulary
       ├─ CustomFieldValue
       └─ Note           internal or customer-visible
```

A `Customer` row describes an organization and is **owned by that same
organization**. The Phase 0 boundary then gives both the customer themselves
(client area) and their upstream reseller or the provider (admin) access to
it, with no special-casing. Reseller organizations also get a customer profile,
because a reseller is commercially a customer of the provider.

## 4. Tables

| Table | Notes |
| --- | --- |
| `staff_users` | Renamed from `users`; adds status, timezone, locale, 2FA columns, `last_login_at` |
| `contacts` | ULID, `customer_id`, portal access flag, nullable password, 2FA columns, communication preferences, `anonymized_at` |
| `customers` | Unique `organization_id`, legal/company name, tax id + type, status, currency, locale, timezone |
| `addresses` | Polymorphic on customer or contact; type, country code, default flag |
| `tags`, `taggables` | Organization-scoped vocabulary, polymorphic assignment |
| `custom_field_definitions`, `custom_field_values` | Per entity type, typed, optionally customer-visible |
| `notes` | Polymorphic, denormalised author label, internal or customer-visible |
| `login_histories` | Append-only; successes and failures, with reason, IP, agent, correlation id |
| `authenticated_sessions` | One row per live session so "sign out other devices" works under any session driver |
| `impersonations` | Who, whom, why, when it started and stopped |

Every one of these carries `organization_id` and uses `BelongsToOrganization`.
`login_histories` allows a null organization, because a failed login has no
identified subject yet.

Deliberately **not** in this phase: customer credit balance. A balance without
an append-only ledger behind it violates the financial-records rule, so credit
arrives with Phase 4 where the ledger is built.

## 5. Authentication

First-party, built on Laravel's native `Auth`, not Fortify. Fortify assumes a
single guard and owns its own routes and views; this phase needs two guards
with different brokers, an organization boundary established at login, and
login history recorded on failures too. Wiring around Fortify would be more
code than the roughly two hundred lines of controllers it replaces. Recorded
as ADR 0017.

- Sign-in, sign-out, password reset for both guards.
- Throttling per email and IP; a failed attempt is recorded either way.
- `password.confirm` on high-risk actions, per guard.
- Sessions are stored in Redis for speed, while `authenticated_sessions` keeps
  a queryable row per session so a user can see and revoke their other
  devices. Revoking destroys the session through the driver's own handler, so
  it works whichever driver an installation configures.

## 6. Two-factor authentication

TOTP via `pragmarx/google2fa`, QR rendered with `bacon/bacon-qr-code`.

- Secret and recovery codes are stored encrypted and are never logged; the
  redaction key list already covers `two_factor` and `recovery_code`.
- Enabling is a three-step flow: generate, confirm with a live code, then
  activate. A secret that is never confirmed never protects anything and never
  locks anyone out.
- Eight single-use recovery codes, regenerable, shown once.
- The challenge screen is rate limited separately from login.

## 7. Impersonation

Staff with `identity.contacts.impersonate` may act as a contact.

- Refused across the organization boundary. A reseller can never impersonate
  another reseller's customer.
- A reason is required and stored.
- Start and stop are both audited, with the correlation id tying the whole
  impersonated session together.
- Rate limited.
- The client layout shows a persistent banner while it is active; the banner
  is not dismissible.
- An impersonating session may not change a password, manage 2FA, or delete
  anything.

## 8. Permissions added

Staff scope: `identity.staff.view`, `identity.staff.manage`,
`identity.contacts.view`, `identity.contacts.manage`,
`identity.contacts.impersonate`, `identity.sessions.manage`,
`crm.customers.view`, `crm.customers.manage`, `crm.customers.export`,
`crm.customers.anonymize`, `crm.notes.view`, `crm.notes.manage`,
`crm.tags.manage`, `crm.custom_fields.manage`, `access.roles.manage` (exists).

Customer scope: `portal.profile.view`, `portal.profile.manage`,
`portal.contacts.manage`, `portal.security.manage`.

The high-risk flag is set on anything that grants access, removes data or
changes money-adjacent configuration.

## 9. Screens

**Admin**: sign-in, password reset, 2FA challenge, profile and security,
staff list and form, role list and form with a permission matrix, customer
list and detail (profile, contacts, addresses, tags, custom fields, notes),
organization list and detail.

**Client**: sign-in, password reset, 2FA challenge, overview, profile,
contacts, security (password, 2FA, sessions), notification preferences.

All of them use the Phase 0 shells and design tokens. Loading, empty and error
states are part of each screen, not a follow-up.

## 10. Testing

Beyond the per-feature success and failure cases:

- Organization isolation for every new table, including the negative case.
- A staff session cannot authenticate against the client guard, or the
  reverse.
- Login throttling, and that a failed attempt is recorded.
- 2FA: unconfirmed secret does not gate login; recovery code is single use.
- Impersonation refused across the boundary, audited on start and stop, and
  blocked from security-sensitive actions.
- Password reset token is guard-scoped.
- Session revocation actually destroys the session.
- Privacy export returns every field, and anonymization leaves audit history
  intact.

## 11. Order of work

1. Migrations, domain enums, models, factories.
2. Authentication for both guards, with login history and session records.
3. Two-factor authentication.
4. Staff and role administration.
5. CRM: customers, contacts, addresses, tags, custom fields, notes.
6. Impersonation.
7. Client area: profile, contacts, security.
8. Privacy export and anonymization.
9. ADRs, docs, result document.

Each step is a separate commit and leaves the suite green.
