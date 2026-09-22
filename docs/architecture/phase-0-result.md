# Phase 0 — Foundation Result

Status: complete
Date: 2026-09-22
Plan: `phase-0-plan.md`
Next phase: Phase 1 (Identity + CRM) — **not started, do not begin without being asked**

---

## 1. What changed against the plan

The plan was written from handoff §14. Re-reading the full document afterwards
surfaced the **V2 addendum**, which supersedes conflicting V1 requirements and
changes Phase 0 in three ways:

| Change | Why |
| --- | --- |
| **Organization ownership added to Phase 0** | V2 §2 requires the `Organization`/`Account` boundary "from Phase 0" and states that reseller isolation must exist from the beginning. This is the one thing that cannot be retrofitted cheaply, so it was built now. See ADR 0002. |
| **`docs/architecture/implementation-plan.md` written** | Required by V2 §24.2, alongside the phase plan. |
| **Roadmap follows V2 §22, not V1 §12** | V2 renumbers the phases (Client Area moves to 5, Public API to 10, Licensing to 14, and Import/Migration and Production Hardening are added). |

Two smaller deviations from the plan document itself:

- Translations live in `lang/{en,tr}` rather than `resources/lang`. `lang/` is
  the framework's own path since Laravel 9; the plan's location would have
  needed a configuration override for no benefit.
- `tests/` is excluded from PHPStan. Explained in §6.

## 2. Commands run

```bash
# Environment
php -v; composer -V; node -v; docker compose version

# Scaffold
git init -b main
composer create-project laravel/laravel:^13.0 .skeleton --no-install
composer install
composer update laravel/horizon
php artisan key:generate
php artisan vendor:publish --tag=sanctum-migrations
php artisan vendor:publish --tag=sanctum-config
php artisan vendor:publish --tag=horizon-config
npm install

# Services and schema
docker compose up -d db redis
php artisan migrate
php artisan db:seed
php artisan migrate --env=testing

# Gates
vendor/bin/pint --test
vendor/bin/rector process --dry-run
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pest
npx eslint .
npx prettier --check .
npm run typecheck
npm run test:unit
npm run build
```

## 3. Results

| Gate | Command | Result |
| --- | --- | --- |
| Composer manifest | `composer validate --strict` | pass |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **111 passed, 285 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 3 passed |
| Build | `vite build` | pass, 27 kB CSS + 182 kB JS (6.2 / 61.7 kB gzipped) |
| Migrations | `php artisan migrate` on MariaDB 11.8 | 8 migrations, clean |

Test breakdown: 9 architecture tests, 34 unit tests, 68 feature tests. The
feature tests run against a real MariaDB 11.8 (`infracms_test`), never SQLite.

### Running application

Verified at `http://infracms.test` (Herd, host PHP, containerised MariaDB and
Redis):

| Route | Status |
| --- | --- |
| `GET /` | 200, storefront renders in light and dark |
| `GET /up` | 200 |
| `GET /api/v1/health` | 200, `database`, `cache` and `redis` all healthy |
| `GET /admin` | 302 to `/admin/login` |
| `GET /client` | 302 to `/login` |

Every response carries `X-Correlation-Id`.

## 4. What was built

### Ownership and authorization

- `organizations` table with a materialised `path`, a hierarchy guard
  (provider is root, resellers under provider, customers under either), and a
  model-level global scope.
- `BelongsToOrganization` filters reads to the acting organization's subtree
  and stamps writes; creating an owned record with no boundary throws.
- `OrganizationContext` stores the boundary in Laravel's `Context`, so queued
  jobs inherit it. `runAs()` and `withoutBoundary()` are the only ways to
  change it, and the boundary never comes from request input.
- `PermissionRegistry` declares 11 core permissions in framework-free code;
  `platform:permissions:sync` mirrors them idempotently and orphans rather
  than deletes. Roles are scoped to staff or customer; assignments are
  polymorphic. One bypass exists, the `super-admin` role, and its use on a
  high-risk capability is audited once per request.

### Cross-cutting foundation

- Error envelope with a 15-member `ErrorCode` enum, translated messages, and
  `details` always encoded as an object.
- Correlation identifiers assigned by the first global middleware, validated
  against ULID/UUID when inbound is trusted, returned in the header, echoed as
  `request_id`, attached to outbound HTTP, and refreshed per scheduled run.
- Append-only `audit_logs` with a fluent `Audit` facade, a swappable
  `AuditRecorder` contract, `FakeAuditRecorder` for tests, model-level refusal
  of updates and deletes, and organization scoping.
- `SecretRedactor` applied to logs, audit payloads and error output, matching
  key fragments case-insensitively at depth plus a card-number safety net.
- Structured JSON logging behind `LOG_STRUCTURED`.
- `StorefrontRenderer` contract so the public storefront is not locked to
  Inertia.

### Interface

- Storefront landing page rendered through the renderer abstraction. It states
  what the installation is and what has to happen before it can sell, and
  deliberately exposes no version or dependency detail to anonymous visitors.
- Admin and client Inertia shells with permission-filtered navigation, skip
  links, `aria-current`, focus-visible treatment, and composed empty states.
- A token-driven design system: semantic CSS custom properties for surfaces,
  text, one accent, status colours, a single 6/10/14 radius scale, tinted
  shadows and motion tokens. Light and dark are both defined and both were
  checked in a browser. Motion is limited to press feedback and hover colour,
  because these shells are opened dozens of times a day and animation there
  reads as latency. `prefers-reduced-motion` is honoured.

### Operations

- `compose.yaml`: PHP-FPM, Nginx, MariaDB 11.8, Redis, Horizon worker,
  scheduler, Mailpit, MinIO, with health checks and a test-schema init script.
- Multi-stage PHP image, development stage running as the host user.
- GitHub Actions: backend job (validate, Pint, Rector, PHPStan, migrate, Pest
  with coverage floor, `composer audit`), frontend job (ESLint, Prettier,
  `vue-tsc`, Vitest, build, `npm audit`), and a packaging job on `main`.

### Documentation

15 ADRs, the implementation plan, the local development runbook, the API error
contract, and `CLAUDE.md`.

## 5. Files

New, by area (excluding `vendor/`, `node_modules/`, `public/build/`):

```text
app/Domain/Access/          CorePermissions, PermissionDefinition, PermissionRegistry,
                            RoleScope, SystemRole, Exceptions/UnknownPermission
app/Domain/Organizations/   OrganizationType, Exceptions/InvalidOrganizationHierarchy
app/Application/Access/     SyncPermissions, PermissionSyncResult
app/Infrastructure/         Access/{Models/Role,Models/Permission,Concerns/HasRoles,PermissionCache}
                            Audit/{Models/AuditLog,DatabaseAuditRecorder}
                            Identity/Models/User
                            Organizations/{Models/Organization,OrganizationBoundary,
                                           Concerns/BelongsToOrganization}
app/Support/                Audit/{AuditEntry,PendingAudit,FakeAuditRecorder,
                                   Contracts/*,Facades/Audit}
                            Correlation/{CorrelationId,CorrelationContext}
                            Errors/{ErrorCode,ErrorEnvelope,PlatformException,
                                    ApiExceptionRenderer,Contracts/*}
                            Logging/{SecretRedactor,RedactSecretsProcessor,
                                     ConfigureStructuredLogging}
                            Organizations/OrganizationContext
                            View/{StorefrontRenderer,BladeStorefrontRenderer}
app/Http/                   Middleware/{AssignCorrelationId,ResolveOrganizationContext,
                                        HandleInertiaRequests}
                            Controllers/{StorefrontController,Admin/DashboardController,
                                         Client/DashboardController,Api/V1/HealthController}
app/Console/Commands/       SyncPermissionsCommand
app/Providers/              AppServiceProvider, PlatformServiceProvider,
                            AccessServiceProvider, OrganizationServiceProvider,
                            HorizonServiceProvider
config/platform.php
database/migrations/        organizations, users, access control, audit logs,
                            personal access tokens
database/factories/         Organization, User, Role, Permission, AuditLog
database/seeders/           ProviderOrganizationSeeder, SystemRoleSeeder, DatabaseSeeder
resources/css/app.css       design tokens
resources/js/               app.ts, types/, composables/usePermissions,
                            Components/{AppButton,AppCard,AppBadge,EmptyState},
                            Layouts/{AdminLayout,ClientLayout},
                            Pages/{Admin,Client}/Dashboard
resources/views/            app.blade.php, storefront/home.blade.php
lang/{en,tr}/               errors, access, organizations, storefront
routes/                     web.php, api.php, console.php
tests/                      Arch/LayeringTest, Unit/{CorrelationId,SecretRedactor,
                            ErrorCode,PermissionRegistry}, Feature/{CorrelationId,
                            ErrorEnvelope,AuditTrail,AccessControl,OrganizationBoundary,
                            Dashboard,HealthEndpoint}
docker/                     php/{Dockerfile,php.ini,opcache-*.ini,entrypoint.sh},
                            nginx/default.conf, mariadb/{custom.cnf,init-test-database.sh}
docs/adr/                   0001-0015 + README
docs/architecture/          implementation-plan, phase-0-plan, phase-0-result
docs/api/errors.md
docs/operations/local-development.md
.github/workflows/ci.yml
compose.yaml, CLAUDE.md, pint.json, phpstan.neon, rector.php,
eslint.config.js, tsconfig.json, vite.config.ts, vitest.config.ts,
.env.example, .env.testing, .dockerignore, .editorconfig, .gitattributes
```

## 6. Unresolved risks and accepted trade-offs

| Item | Detail | Follow-up |
| --- | --- | --- |
| **PHPStan does not analyse `tests/`** | PHPStan resolves `$this` inside a Pest closure to `Pest\PendingCalls\TestCall`, so every `$this->getJson(...)` reports a false "undefined method". Tests are covered by Pint, Rector and architecture tests instead. | Re-enable when Pest ships a PHPStan scope extension. |
| **`ext-pcntl` / `ext-posix` declared in `composer.config.platform`** | Horizon requires `pcntl`, which Windows lacks. The declaration makes resolution match Linux, where they genuinely exist. It also means a genuinely missing extension in production would not be caught by `composer install`. | Phase 17 environment check must verify extensions at runtime. |
| **Coverage floor is 70 % in CI, unenforced locally** | No coverage driver on the build host; CI installs pcov. | Raise as domain code lands. |
| **One PHPStan ignore** | `BladeStorefrontRenderer` passes a runtime-resolved string where larastan wants `view-string`. Existence is checked with `exists()` first. `reportUnmatchedIgnoredErrors` is on, so a stale ignore fails the build. | None; revisit if larastan gains a narrowing helper. |
| **Admin and client shells cannot be viewed in a browser yet** | Sign-in is Phase 1. Both redirect to paths that do not exist. Their rendering is covered by feature tests asserting the Inertia component and props. | Phase 1. |
| **Docker image not built end to end** | `compose.yaml` and the Dockerfile are written; only `db` and `redis` were started, because the application runs under Herd on this machine. | Build and smoke-test `app`, `web`, `queue`, `scheduler` before the first deployment. |
| **CI workflow not executed** | No remote is configured, so no run has happened. | Push to a remote and confirm the first run. |
| **Roles are installation-global** | Resellers share the role catalogue. Correct for Phase 0; the commercial model may need per-reseller roles. | Decide in Phase 13. |
| **No model-level enforcement that owned tables use the trait** | A future model that omits `BelongsToOrganization` is silently unscoped. | Add an architecture test once a second owned context exists (Phase 1). |
| **`AuditLog` rows with `organization_id` null** | Installation-level events are invisible to any bounded query by design. Provider-wide audit UI must query unscoped. | Handle in the Phase 9 audit UI. |

## 7. Exact next recommended task

**Phase 1 — Identity + CRM, first slice: staff authentication and the first
account.**

Concretely, and in this order:

1. Split `App\Infrastructure\Identity\Models\User` into staff users and
   customer contacts, each with its own guard and role scope, both owned by an
   organization. Migrate the Phase 0 `users` table rather than replacing it.
2. Ship sign-in, sign-out, password reset and session management for staff at
   `/admin/login`, and for customers at `/login` — the two paths Phase 0's
   middleware already redirects to.
3. Add an `installer:create-owner` console command that creates the first
   staff account inside the provider organization and assigns `super-admin`,
   so a fresh installation is reachable without seeding by hand.
4. TOTP 2FA with recovery codes, login history, and impersonation that is
   audited and rate limited.
5. Admin screens for staff, roles and permissions, using the registry and the
   permission-filtered navigation the shells already expect.
6. Customer and contact CRUD with addresses, tax IDs, custom fields and tags,
   all organization-scoped.

Definition of Done for that slice: organization-isolation tests for every new
table, policies for every screen, audit records for impersonation and role
changes, translations in both locales, and all eight gates green.

**Do not begin Phase 1 without being asked.**
