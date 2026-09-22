# 0016 — Two authenticatables, two guards

Status: accepted
Date: 2026-09-23

## Context

Phase 0 shipped a single placeholder account, documented as a placeholder.
Phase 1 has to serve two populations who share almost nothing: provider and
reseller staff, who administer the platform, and the people at a customer
organization, who use the portal.

The obvious shortcut is one `users` table with a `type` column and one guard.
It is also the shortcut that produces the worst possible bug: a guard
resolution that returns the wrong kind of account, and a customer holding a
staff session.

## Decision

Two tables and two guards.

| Model | Table | Guard | Role scope |
| --- | --- | --- | --- |
| `StaffUser` | `staff_users` | `staff` | Staff |
| `Contact` | `contacts` | `client` | Customer |

The reason that matters is the session. Separate guards mean separate session
keys, so a staff session and a customer session cannot be confused by any
amount of guard-resolution confusion: they are never in the same place. The
rest follows from that separation rather than justifying it:

- Different columns. Contacts carry `customer_id`, communication preferences
  and a primary flag; staff carry none of those.
- Different role scopes, enforced at the model rather than by a runtime check
  somebody can forget.
- Different password brokers with separate token tables, so a staff reset
  token cannot be redeemed on the client area.

Both models implement `PlatformAccount`, a framework-free domain contract, so
sign-in, the two-factor challenge and session management are written once and
work against either. `AuthenticatableAccount` joins that to Laravel's
`Authenticatable` for the infrastructure layer.

A contact is a person on file first and an account second. `portal_access`
separates the billing contact who exists only so invoices reach the right
inbox from the person who signs in.

## Consequences

- `CurrentActor` exists because `$request->user()` resolves the default
  guard, which in a two-guard application silently answers the wrong
  question. Everything that needs "the current subject" asks there.
- Route files are per area, each with its own guard, so a route cannot end up
  on the wrong guard by being declared in the wrong place.
- The shared auth controllers read their guard from the route-name prefix.
- Reseller staff are staff. They authenticate on the same guard and see only
  their own subtree, which is what makes reseller support work at all.
