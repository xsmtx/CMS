# Phase 0 — Foundation Plan

Status: approved for implementation
Date: 2026-09-22
Scope: Phase 0 only (repository, Docker, Laravel/Vue/TS/Inertia, MariaDB/Redis, CI,
coding standards, ADRs, error envelope, correlation IDs, audit foundation, RBAC
foundation). Phase 1 is explicitly out of scope.

---

## 1. Repository inspection (pre-existing state)

The working directory `C:\Users\Samet\Desktop\infracms` contained exactly one file
before this phase:

| Path                                    | Notes                              |
| --------------------------------------- | ---------------------------------- |
| `CLAUDE_HOSTING_PLATFORM_HANDOFF_V2.md` | 32 KB product/architecture handoff |

There was **no** git repository, no `composer.json`, no `package.json`, no
application code, no CI configuration and no `docs/` tree. Phase 0 therefore starts
from a greenfield checkout; nothing has to be migrated or preserved apart from the
handoff document itself, which is retained at the repository root as the product
specification of record.

Verified toolchain on the build host:

| Tool     | Version found     | Required by plan  |
| -------- | ----------------- | ----------------- |
| PHP CLI  | 8.4.25 (NTS, x64) | ≥ 8.4             |
| Composer | 2.10.1            | ≥ 2.8             |
| Node     | 24.17.0           | ≥ 22 LTS          |
| npm      | 11.13.0           | ≥ 10              |
| Docker   | 29.5.3            | ≥ 26 (Compose v2) |
| Git      | 2.54.0            | ≥ 2.40            |

PHP extensions present locally: `bcmath ctype curl dom fileinfo filter gd gmp iconv
intl json libxml mbstring mysqlnd openssl pcre pdo pdo_mysql redis session sodium
tokenizer xml zip opcache`. Every extension the platform needs is available, so local
(non-Docker) execution of the test suite is possible as a fallback. `pcntl` and
`posix` are absent on Windows — Horizon's supervisor therefore only runs inside the
Linux container, which the Docker plan accounts for.

Resolvable dependency versions at implementation time (queried from packagist/npm):
`laravel/framework v13.33.0`, `laravel/laravel v13.10.1` skeleton,
`inertiajs/inertia-laravel v3.3.4`, `laravel/sanctum v4.3.3`, `pestphp/pest v5.2.1`,
`larastan/larastan v3.12.2`, `laravel/pint v1.32.1`, `@inertiajs/vue3 3.7.1`,
`vue 3.5.43`, `tailwindcss 4.3.3`, `vite 8.3.0`, `typescript 7.0.2`, `vue-tsc 3.3.11`,
`vitest 5.0.1`, `eslint 10.11.0`.

---

## 2. Proposed folder structure

```text
.
├── app/
│   ├── Domain/                     # framework-free business core
│   │   ├── Shared/                 # cross-context value objects, domain events base
│   │   └── Access/                 # RBAC domain: permission registry, role contracts
│   ├── Application/                # use cases: commands, queries, handlers, DTOs
│   │   └── Access/
│   ├── Infrastructure/             # Eloquent, adapters, external clients
│   │   ├── Access/Models/
│   │   ├── Audit/                  # audit writer, redaction
│   │   └── Identity/Models/        # User (placeholder until Phase 1)
│   ├── Http/
│   │   ├── Controllers/{Admin,Client,Api}/
│   │   ├── Middleware/
│   │   └── Requests/
│   ├── Providers/
│   └── Support/                    # cross-cutting plumbing
│       ├── Correlation/            # correlation id generation/propagation
│       ├── Errors/                 # error envelope, error codes, domain exceptions
│       ├── Logging/                # JSON formatter, redacting processor
│       └── Audit/                  # public Audit facade/service contract
├── modules/                        # first/third-party extension modules (Phase 10)
│   └── .gitkeep
├── themes/
│   ├── storefront/ client/ admin/  # theme packages (Phase 9)
├── bootstrap/ config/ database/ public/ routes/ storage/
├── resources/
│   ├── css/app.css
│   ├── js/                         # Vue 3 + TS + Inertia
│   │   ├── app.ts  ssr.ts
│   │   ├── Layouts/ Pages/ Components/ Composables/ types/
│   └── lang/{en,tr}/               # translatable strings
├── tests/
│   ├── Arch/                       # layering / dependency-direction tests
│   ├── Unit/ Feature/
│   ├── Pest.php  TestCase.php
├── docs/
│   ├── adr/                        # architecture decision records
│   ├── api/ architecture/ modules/ operations/
├── docker/
│   ├── php/{Dockerfile,php.ini,opcache.ini,entrypoint.sh}
│   ├── nginx/default.conf
│   └── mariadb/custom.cnf
├── .github/workflows/ci.yml
├── compose.yaml
├── CLAUDE.md
└── CLAUDE_HOSTING_PLATFORM_HANDOFF_V2.md
```

Rationale for the four-layer split inside `app/`:

- **Domain** — entities, value objects, domain events, and _contracts_ (interfaces)
  for anything external. No Laravel imports. Enforced by an architecture test.
- **Application** — orchestrates domain objects for one use case; may depend on
  Domain and on contracts, never on Infrastructure concretions.
- **Infrastructure** — Eloquent models, repositories, provider SDK adapters, queue
  and cache implementations. Depends inward only.
- **Http** — thin interface layer: validate, authorize, call an application service,
  render. No business rules, no provider SDKs.
- **Support** — cross-cutting technical concerns that are not a bounded context
  (correlation IDs, error envelope, logging, redaction).

Eloquent models deliberately do **not** live in `app/Models`. The Laravel skeleton's
`App\Models\User` is relocated to `App\Infrastructure\Identity\Models\User` in this
phase and `config/auth.php` is updated accordingly; Phase 1 expands it into a full
Identity context. Keeping a single global `Models` namespace would encourage exactly
the cross-context table access the handoff forbids.

---

## 3. Dependencies and justification

### 3.1 Composer (runtime)

| Package                     | Constraint        | Why                                                                                                                                                                                                                                                                      |
| --------------------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `php`                       | `^8.4`            | Property hooks, asymmetric visibility, `#[\Deprecated]`; baseline in handoff.                                                                                                                                                                                            |
| `laravel/framework`         | `^13.0`           | Preferred framework version; brings queues, scheduler, Context, encryption.                                                                                                                                                                                              |
| `laravel/tinker`            | `^2.10`           | REPL for operational debugging.                                                                                                                                                                                                                                          |
| `inertiajs/inertia-laravel` | `^3.3`            | Server-driven SPA for admin/client surfaces without a separate API client.                                                                                                                                                                                               |
| `laravel/sanctum`           | `^4.3`            | SPA session auth + API tokens with scopes for `/api/v1`.                                                                                                                                                                                                                 |
| `laravel/horizon`           | `^5.50 \|\| ^6.0` | Redis queue supervision, metrics, failed-job UI. Resolved to whichever release declares Laravel 13 support; if none does at install time, Horizon is deferred and plain `queue:work` + a documented supervisor config is used instead (recorded in the result document). |

Deliberately **not** installed in Phase 0, with the phase that owns them:
`brick/money` (Phase 2 — money VO), `barryvdh/laravel-dompdf` or `spatie/laravel-pdf`
(Phase 4 — invoice PDF), `stripe/stripe-php` / PayPal SDK (Phase 4),
`league/flysystem-aws-s3-v3` (Phase 8 — ticket attachments; MinIO config is prepared
now but the driver is added with its first consumer), `laravel/scout` (Phase 12).
Adding them now would create unused attack surface and slow every CI run.

Redis is accessed through the **phpredis** extension (present in the image and on the
host), not Predis: lower latency and no userland serialization surprises.

### 3.2 Composer (dev)

| Package                                                     | Constraint        | Why                                                                                                                                      |
| ----------------------------------------------------------- | ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `pestphp/pest` + `pest-plugin-laravel`                      | `^5.2`            | Test runner; built-in architecture testing used to enforce layering.                                                                     |
| `larastan/larastan`                                         | `^3.12`           | PHPStan + Laravel extension. Level 8 at Phase 0, raised to `max` once the codebase is large enough to justify a baseline.                |
| `laravel/pint`                                              | `^1.32`           | Canonical formatter; `laravel` preset plus strict-types and import-order rules.                                                          |
| `rector/rector` + `driftingly/rector-laravel`               | `^2.0` / `^2.0`   | Automated upgrade path across PHP/Laravel majors — a long-lived commercial product will pay for this repeatedly. Runs `--dry-run` in CI. |
| `nunomaduro/collision`, `mockery/mockery`, `fakerphp/faker` | skeleton defaults | Test ergonomics.                                                                                                                         |
| `laravel/pail`                                              | `^1.2`            | Tail structured logs in dev.                                                                                                             |

### 3.3 npm

| Package                                                                                 | Why                                                                                     |
| --------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| `vue@^3.5`, `@vitejs/plugin-vue`                                                        | UI framework per handoff.                                                               |
| `@inertiajs/vue3@^3.7`                                                                  | Matches `inertia-laravel ^3.3`.                                                         |
| `typescript@^7`, `vue-tsc@^3.3`                                                         | Type safety; `vue-tsc --noEmit` is a CI gate.                                           |
| `vite@^8`, `laravel-vite-plugin`                                                        | Build/dev server.                                                                       |
| `tailwindcss@^4`, `@tailwindcss/vite`                                                   | Design-system primitives; v4 uses the Vite plugin, no PostCSS config.                   |
| `eslint@^10`, `eslint-plugin-vue`, `typescript-eslint`, `@vue/eslint-config-typescript` | Lint gate (flat config).                                                                |
| `prettier`, `prettier-plugin-tailwindcss`                                               | Formatting gate.                                                                        |
| `vitest@^5`, `@vue/test-utils`, `happy-dom`                                             | Frontend unit tests.                                                                    |
| `axios`                                                                                 | Only if a non-Inertia call is needed; installed because Sanctum CSRF bootstrap uses it. |

No UI component library is added. The handoff requires an _internal accessible design
system_; a third-party kit would fight the theming/white-label requirements of Phase 9.

---

## 4. Docker services

`compose.yaml` (Compose v2, no `version:` key) defines the dev environment. Nothing in
the platform requires Kubernetes.

| Service     | Image / build                       | Purpose                        | Notes                                                                                                                                                         |
| ----------- | ----------------------------------- | ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app`       | `docker/php` (php:8.4-fpm-bookworm) | PHP-FPM application            | Extensions: `pdo_mysql bcmath intl gd zip opcache sodium gmp pcntl exif` + pecl `redis`. Non-root `www-data` UID/GID mapped.                                  |
| `web`       | `nginx:1.27-alpine`                 | HTTP front end                 | Serves `public/`, proxies to `app:9000`. Port `8080`.                                                                                                         |
| `db`        | `mariadb:11.8`                      | Primary datastore              | `utf8mb4_unicode_ci`, `innodb_file_per_table`, `transaction_isolation=READ-COMMITTED`, named volume. Second database `infracms_test` created by init script. |
| `redis`     | `redis:7.4-alpine`                  | Cache, sessions, queues, locks | `appendonly yes`, own volume.                                                                                                                                 |
| `queue`     | same build as `app`                 | Horizon (or `queue:work`)      | Separate container so worker restarts do not touch web traffic.                                                                                               |
| `scheduler` | same build as `app`                 | `php artisan schedule:work`    | One replica only; emits a heartbeat for monitoring.                                                                                                           |
| `mailpit`   | `axllent/mailpit`                   | SMTP sink + web UI on `8025`   | No real mail ever leaves dev.                                                                                                                                 |
| `minio`     | `minio/minio`                       | S3-compatible object storage   | Console `9001`; bucket created by a one-shot `mc` init container.                                                                                             |

Vite runs on the **host** (`npm run dev`) rather than in a container: hot reload over a
bind mount on Windows is materially slower and adds no fidelity. `vite.config.ts` sets
`server.host` and the HMR host so the containerized app can reference the dev server.

Health checks are defined for `db`, `redis` and `minio`; `app`, `queue` and
`scheduler` declare `depends_on: { condition: service_healthy }` so the stack comes up
deterministically.

---

## 5. Environment variables

`.env.example` is the contract. Beyond the Laravel defaults, Phase 0 introduces:

| Variable                                                    | Default (dev)                                 | Meaning                                                                                           |
| ----------------------------------------------------------- | --------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `APP_NAME`                                                  | `InfraCMS`                                   | Internal app name (white-label branding is a DB setting, not env).                                |
| `APP_URL`                                                   | `http://localhost:8080`                       | Absolute URL generation.                                                                          |
| `DB_CONNECTION`                                             | `mariadb`                                     | Explicit MariaDB driver, not the generic `mysql` one.                                             |
| `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`                   | `db` / `3306` / `infracms` / `infracms` / — | Primary connection.                                                                               |
| `DB_TEST_DATABASE`                                          | `infracms_test`                              | Used by `phpunit.xml`; tests never touch the dev database.                                        |
| `REDIS_CLIENT`                                              | `phpredis`                                    | Extension-backed client.                                                                          |
| `REDIS_HOST/PORT/PASSWORD`                                  | `redis` / `6379` / null                       | Cache/queue/session/lock backend.                                                                 |
| `CACHE_STORE`                                               | `redis`                                       | —                                                                                                 |
| `SESSION_DRIVER`                                            | `redis`                                       | —                                                                                                 |
| `SESSION_SECURE_COOKIE`                                     | `false` dev / `true` prod                     | Enforced in prod config check.                                                                    |
| `QUEUE_CONNECTION`                                          | `redis`                                       | —                                                                                                 |
| `HORIZON_PREFIX`                                            | `infracms_horizon:`                          | Namespaces Horizon keys per installation.                                                         |
| `LOG_CHANNEL`                                               | `stack`                                       | —                                                                                                 |
| `LOG_STRUCTURED`                                            | `false` dev / `true` prod                     | Switches the stderr handler to the JSON formatter.                                                |
| `LOG_LEVEL`                                                 | `debug` / `info`                              | —                                                                                                 |
| `CORRELATION_ID_HEADER`                                     | `X-Correlation-Id`                            | Inbound/outbound correlation header name.                                                         |
| `CORRELATION_ID_TRUST_INBOUND`                              | `false`                                       | Whether to accept a client-supplied ID (true only behind a trusted proxy).                        |
| `AUDIT_RETENTION_DAYS`                                      | `0` (keep forever)                            | Operational retention knob; `0` disables pruning.                                                 |
| `MAIL_MAILER/HOST/PORT`                                     | `smtp` / `mailpit` / `1025`                   | Dev mail sink.                                                                                    |
| `FILESYSTEM_DISK`                                           | `local`                                       | `s3` in prod.                                                                                     |
| `AWS_*`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`      | MinIO values                                  | Object storage.                                                                                   |
| `PLATFORM_INSTALLATION_ID`                                  | empty                                         | Persistent installation UUID; generated on first boot and stored, consumed by Phase 11 licensing. |
| `LICENSE_API_URL`, `LICENSE_KEY`, `LICENSE_PUBLIC_KEY_PATH` | empty                                         | Declared now so deployments are forward-compatible; unused until Phase 11.                        |

Secrets are never logged: the redacting log processor (§9) strips any key matching the
configured secret patterns before a record is written.

---

## 6. CI stages

`.github/workflows/ci.yml`, one workflow, two jobs that can run in parallel plus a
gating job.

**Job `backend`** (ubuntu-latest, services: `mariadb:11.8`, `redis:7.4`)

1. `composer validate --strict`
2. `composer install --prefer-dist --no-interaction` (cached)
3. `vendor/bin/pint --test` — formatting gate
4. `vendor/bin/rector process --dry-run` — upgrade/quality gate
5. `vendor/bin/phpstan analyse --memory-limit=1G` — level 8
6. `php artisan key:generate` + `php artisan migrate --force` against the service DB
7. `vendor/bin/pest --coverage --min=75` (includes architecture tests)
8. `composer audit --locked`

**Job `frontend`** (ubuntu-latest, node 24)

1. `npm ci`
2. `npx eslint .`
3. `npx prettier --check .`
4. `npm run typecheck` (`vue-tsc --noEmit`)
5. `npm run test:unit` (vitest, `--run`)
6. `npm run build`
7. `npm audit --audit-level=high` (non-blocking warning at Phase 0, blocking from Phase 4)

**Job `package`** (needs both, `main` only) — builds the deployable artifact: production
composer install (`--no-dev --optimize-autoloader`), `npm ci && npm run build`, then a
tarball excluding `.git`, `node_modules`, `tests`, uploaded as a workflow artifact.
This is the seed of the Phase 13 installer package.

Coverage threshold starts at 75 % and is raised as domain code lands; the number is
recorded in `phpunit.xml` and the workflow so it cannot silently drift.

---

## 7. RBAC approach

Explicit rejection of `is_admin`: authorization is always _permission-based_.

**Model**

- Permissions are **declared in code** by a `PermissionRegistry`, grouped by bounded
  context, each with a stable slug (`billing.invoice.refund`), a translation key and a
  risk flag (`requires_confirmation`). Modules contribute permissions through the same
  registry in Phase 10.
- Permissions are **mirrored into the database** (`permissions` table) by an idempotent
  `platform:permissions:sync` command so roles can reference them with real foreign
  keys and the admin UI can list them. A permission removed from code is marked
  `orphaned` rather than deleted, so an audit trail survives.
- `roles` are per-**scope** (`staff` or `customer`) so a customer role can never be
  attached to a staff permission set. System roles (`super-admin`, `owner`) are seeded
  and undeletable.
- `role_permission` pivot; `role_assignments` is a polymorphic pivot
  (`role_id`, `subject_type`, `subject_id`) so both staff users and — later — customer
  contacts and API tokens can carry roles without schema churn.

**Runtime**

- `HasRoles` trait on authenticatable models: `assignRole`, `revokeRole`, `hasRole`,
  `hasPermissionTo`. Effective permissions are resolved once per request and cached in
  Redis under a per-subject key that is invalidated on any assignment change.
- A single `Gate::before` grants everything **only** to the `super-admin` system role,
  and that grant is itself audited when it is the deciding factor.
- Everything else goes through Gates/Policies. `Gate::define` is populated from the
  registry so `can('billing.invoice.refund')` works out of the box; policies added per
  context from Phase 1 onwards.
- Sanctum token abilities are intersected with the subject's effective permissions —
  a token can never exceed its owner.

Phase 0 ships the registry, tables, trait, sync command, `super-admin` seeding and
tests. It does **not** ship the role management UI (Phase 1).

---

## 8. Audit approach

- Single append-only table `audit_logs` with a ULID primary key and **no**
  `updated_at`. The Eloquent model blocks `updating`/`deleting` at the model layer and
  the table grants no application-level update path.
- Recorded fields: `action` (dotted verb, e.g. `service.suspended`), `actor_type`,
  `actor_id`, `actor_label` (denormalized snapshot so a deleted actor is still
  legible), `target_type`, `target_id`, `changes` (JSON diff, redacted), `metadata`
  (JSON, redacted), `reason`, `ip_address`, `user_agent`, `correlation_id`,
  `occurred_at`.
- Public API: `Audit::record()` returning a small fluent builder
  (`->action()->on($model)->by($actor)->changed($before,$after)->because($reason)`).
  Writing goes through an `AuditRecorder` contract so tests can fake it and a future
  module can mirror records to an external SIEM.
- Diffs are computed from `$model->getChanges()` / `getOriginal()` and passed through
  the same `SecretRedactor` used by logging, so a password hash or API key never
  reaches the table.
- Indexes: `(target_type, target_id, occurred_at)`, `(actor_type, actor_id,
occurred_at)`, `(action, occurred_at)`, `correlation_id`.
- Writes are synchronous by default (an audit record must not be lost if the queue is
  down) with a documented path to a dedicated queue connection for high-volume actions.

Phase 0 ships the table, recorder, redactor, model guards and tests. Per-feature audit
calls arrive with each feature, as the Definition of Done requires.

## 9. Correlation-ID approach

- `AssignCorrelationId` middleware runs first in the global stack. It accepts an
  inbound `X-Correlation-Id` **only** when `CORRELATION_ID_TRUST_INBOUND=true` and the
  value is a valid ULID/UUID of bounded length; otherwise it generates a ULID.
- The value is pushed into Laravel's `Context`, which propagates automatically into
  queued jobs, and is therefore present in every log record without per-call plumbing.
- The response carries the ID back in the same header, and the error envelope includes
  it as `error.request_id` so a customer can quote one string to support.
- Outbound HTTP through a shared `Http::macro`/client factory attaches the header, so a
  provider's logs can be joined to ours.
- A `ScheduledTaskStarting` listener assigns a fresh correlation ID per scheduled run,
  which also becomes the `automation_runs` identifier in Phase 7.

## 10. Error envelope

Every JSON/API response that is not a success uses exactly:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": { "email": ["The email field is required."] },
    "request_id": "01K5Q8ZP4F8T7M0QX9J0K3N2VB"
  }
}
```

- `code` is a stable snake_case token from an `ErrorCode` enum — clients switch on it,
  never on `message`.
- `message` is translatable and safe to show a user; internal exception text is never
  leaked in production.
- Domain exceptions implement `ProvidesErrorCode` + `ProvidesErrorDetails`; the
  exception handler maps everything else (validation, auth, 404, 429, 500) onto the
  same shape with a sensible status code.
- HTML/Inertia responses are unaffected; the envelope applies to `/api/*` and to any
  request that negotiates JSON.

## 11. Logging approach

- `stack` channel → `stderr` handler. When `LOG_STRUCTURED=true` the handler uses a
  JSON formatter emitting `timestamp, level, message, channel, context, extra,
correlation_id, environment, release`.
- A Monolog processor applies `SecretRedactor` to `context` and `extra`. Patterns are
  configured in `config/platform.php` (`password`, `secret`, `token`, `api_key`,
  `private_key`, `authorization`, `card`, `cvv`, `iban`, `pan`) and matched
  case-insensitively against keys at any nesting depth; matched values become
  `[redacted]`.
- Daily-file logging remains available for single-server installs that have no log
  shipper.

## 12. Test strategy

| Layer        | Tooling             | Phase 0 content                                                                                                                                                                                                               |
| ------------ | ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Architecture | Pest arch tests     | `Domain` may not depend on `Illuminate`/`App\Infrastructure`/`App\Http`; `Application` may not depend on `App\Http`; no `dd/dump/var_dump/ray`; all classes in `App\Domain` are `final` or abstract; strict types everywhere. |
| Unit         | Pest                | Correlation ID generation/validation, error-code mapping, secret redaction (nested arrays, key casing), permission registry resolution.                                                                                       |
| Feature      | Pest + real MariaDB | Correlation header round-trip, error envelope for validation/auth/404/500, audit record written with redacted diff and immutability enforced, permission sync idempotency, `super-admin` gate, health endpoint.               |
| Frontend     | Vitest              | App bootstrap and one shared component render; typecheck via `vue-tsc`.                                                                                                                                                       |

Tests run against **MariaDB**, not SQLite, in both local Docker and CI. SQLite would
hide collation, JSON-function and foreign-key behaviour that this product depends on.
`RefreshDatabase` with a dedicated `infracms_test` schema; factories live beside their
Infrastructure models.

Rules that will be enforced from later phases but are set up now: no test may perform a
real paid provider call (an arch test forbids importing provider SDK namespaces in
`tests/`), and every queue-consuming feature must have an idempotency test.

## 13. ADRs to be written in Phase 0

| ID   | Title                                                                          |
| ---- | ------------------------------------------------------------------------------ |
| 0001 | Modular monolith with Domain/Application/Infrastructure/Interface layers       |
| 0002 | ULIDs as public aggregate identifiers                                          |
| 0003 | Stable JSON error envelope and error-code taxonomy                             |
| 0004 | Correlation IDs propagated through Laravel Context                             |
| 0005 | Append-only audit log with secret redaction                                    |
| 0006 | First-party permission-based RBAC (no `is_admin`, no third-party ACL package)  |
| 0007 | Redis + Horizon as the queue and scheduling baseline                           |
| 0008 | Inertia for admin/client, renderer abstraction to keep the storefront portable |
| 0009 | Structured JSON logging with redaction                                         |
| 0010 | Quality gates: Pint, PHPStan level 8, Rector, Pest with architecture tests     |
| 0011 | Docker Compose for development; Kubernetes never required to install           |
| 0012 | Licensing control plane is a separate application and database                 |
| 0013 | Money as integer minor units + ISO 4217 code, never float                      |

0012 and 0013 record decisions whose _implementation_ belongs to Phases 11 and 2/4
respectively; writing them now prevents the rest of the codebase from assuming
otherwise.

## 14. Implementation order

1. `git init`, `.gitignore`, `.gitattributes`, `.editorconfig`, `CLAUDE.md`.
2. Laravel 13 skeleton into the repository root; relocate `App\Models\User`; adopt the
   four-layer namespace map in `composer.json` autoload.
3. Docker: `docker/php`, `docker/nginx`, `docker/mariadb`, `compose.yaml`,
   `.env.example`, `.dockerignore`.
4. Quality tooling: `pint.json`, `phpstan.neon`, `rector.php`, `eslint.config.js`,
   `.prettierrc`, `tsconfig.json`, npm scripts.
5. Frontend: Vue 3 + TS + Inertia + Tailwind 4 wiring, admin/client shells,
   `resources/lang/{en,tr}`.
6. `App\Support`: correlation, error envelope, logging/redaction, `config/platform.php`.
7. Audit foundation: migration, model, recorder, tests.
8. RBAC foundation: registry, migrations, models, `HasRoles`, sync command, seeder,
   tests.
9. Health endpoint + `/api/v1` skeleton with the error envelope applied.
10. ADRs 0001–0013, `docs/operations/local-development.md`, `docs/api/errors.md`.
11. Run Pint, PHPStan, Rector, Pest, eslint, typecheck, vitest, build.
12. Write `docs/architecture/phase-0-result.md`.

Each numbered step is a separate commit with a conventional-commit subject.

## 15. Known risks carried into implementation

- **Horizon on Laravel 13** — no stable Horizon release advertising Laravel 13 support
  was confirmed while planning. Fallback is documented in §3.1; the outcome is recorded
  in the result document.
- **TypeScript 7 / Vite 8 / ESLint 10 freshness** — all are current majors; if a plugin
  in the chain lags, the plan pins the last compatible minor rather than downgrading
  the toolchain wholesale.
- **Windows host** — `pcntl`/`posix` are unavailable, so Horizon, `schedule:work`
  supervision and signal handling are validated only inside the Linux containers.
- **Coverage threshold** — 75 % is meaningful for a foundation phase but must rise as
  domain logic lands; tracked as a follow-up in the result document.
