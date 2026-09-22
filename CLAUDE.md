# InfraCMS — working notes for Claude Code

Modular, white-label hosting automation platform. Laravel 13, MariaDB 11.8,
Redis, Vue 3 + TypeScript + Inertia.

**Read first:** `CLAUDE_HOSTING_PLATFORM_HANDOFF_V2.md` is the product spec of
record (the V2 addendum supersedes conflicting V1 sections).
`docs/architecture/implementation-plan.md` holds the roadmap and sequencing.
`docs/adr/` holds the decisions — read the relevant one before changing
anything it covers.

## Layering

```text
app/Domain          entities, value objects, enums, contracts — no framework imports
app/Application     one class per use case; depends on Domain + contracts
app/Infrastructure  Eloquent, adapters, queues, cache — depends inward only
app/Http            validate, authorize, call a use case, render — no business rules
app/Support         cross-cutting: correlation, errors, logging, audit, organizations
modules/            extension modules (Phase 12)
themes/             theme packages (Phase 11)
```

Architecture tests in `tests/Arch` enforce this. If one fails, the fix is the
code, not the test.

Eloquent models live in `app/Infrastructure/<Context>/Models`, never in a
global `App\Models`. Factory resolution is configured for this in
`AppServiceProvider`; a new model needs a factory in `database/factories`
named `<ModelName>Factory`.

## Non-negotiables

1. **Ownership.** Every owned table carries `organization_id` and its model
   uses `BelongsToOrganization`. Never add an owned table without it.
   `OrganizationContext::withoutBoundary()` is the only escape hatch and every
   call site must be justified.
2. **Authorization is two questions**, asked in order: organization boundary,
   then permission. A permission check alone is not enough.
3. **No `is_admin`.** Declare a permission in `CorePermissions`, check it with
   a gate or policy. `super-admin` is the only bypass.
4. **Money is integer minor units + ISO currency.** `float` never touches a
   monetary path.
5. **Financial and audit history is append-only.** Never silently mutate a
   finalised record.
6. **Provider logic lives behind a contract** in `Infrastructure`. Never a
   provider SDK in a controller, model or application service.
7. **Never log or store a secret.** `SecretRedactor` is the safety net, not
   the plan. Raw card data is never stored at all.
8. **External calls** need a timeout, bounded retries with backoff, a
   correlation ID and a sanitised structured error. Never hold a database
   transaction open across a remote call.
9. **Queued and webhook operations are idempotent.** Deduplicate on the
   provider's event ID or an idempotency key.
10. **All user-facing strings are translatable** (`lang/en`, `lang/tr`).
11. **Errors use the envelope** in `docs/api/errors.md` — add an `ErrorCode`
    member, never an ad-hoc message shape.
12. **Architectural deviation requires an ADR** in `docs/adr/`.

## Commands

```bash
composer check      # pint --test, rector --dry-run, phpstan, pest
composer fix        # apply Pint
npm run lint        # eslint
npm run typecheck   # vue-tsc --noEmit
npm run test:unit   # vitest
npm run build       # production assets

php artisan platform:permissions:sync
php artisan db:seed                # provider org, permissions, system roles
php artisan identity:create-owner  # the first staff account
php artisan migrate --env=testing
```

Tests run against **MariaDB**, never SQLite — start it with
`docker compose up -d db redis` first. See
`docs/operations/local-development.md`.

## Definition of Done

Migrations reviewed and reversible where practical; policies and permissions
exist; validation exists; tests cover success, failure, idempotency and
organization isolation; audit records exist for sensitive actions;
translations exist; API docs updated; secrets redacted; UI handles loading,
empty and error states; indexes match real access patterns; all gates pass;
operational docs updated. No `TODO` silently defers an acceptance criterion.

## Current state

Phases 0 to 3 are complete (`docs/architecture/phase-0-result.md` through
`phase-3-result.md`). Phase 4, Billing + Payments, is next and is not
started. Do not begin a phase without being asked for it.

Two guards exist: `staff` (admin, at `/admin`) and `client` (portal, signing
in at `/login`). Use `CurrentActor` rather than `$request->user()`, which
resolves only the default guard. Route files are per area, and the shared
auth controllers read their guard from the route-name prefix.

The organization boundary is forced ahead of `SubstituteBindings` in the
middleware priority list. Do not reorder it: route-model binding resolved
before the boundary exists is an unscoped lookup.

Money is never a float, anywhere: not in a column, a DTO, a JSON payload or
the browser. `App\Domain\Shared\Money` holds integer minor units and an ISO
4217 code, and has no `toFloat()` on purpose. A price exists for a billing
cycle and currency only when a row exists for it — absence means not sold,
zero means free, and nothing is ever converted at display time.

The public storefront takes its boundary from the installation rather than
from an actor, and narrows to exactly one organization rather than the
subtree a boundary normally means.

An order line copies the catalog rather than referencing it: the product
name, the option labels, the cycle and every amount are written onto the
line when the order is placed (ADR 0021). Invoice lines in Phase 4 copy from
the order line for the same reason. A cart is the other way round — its
lines reference the catalog and are priced on every read.

Tax and risk are contracts with dull defaults (ADR 0022). Core never
implements a country's tax rules and never names a fraud vendor. Risk holds
rather than refuses, and a rejection never tells the customer which rule
fired.

The admin sidebar follows the admin panel map in the handoff, which is the
shape WHMCS operators know. Only sections that exist are listed; each phase
adds its own rows.
