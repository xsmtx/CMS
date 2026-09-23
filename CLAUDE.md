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

Phases 0 to 9 are complete (`docs/architecture/phase-0-result.md` through
`phase-9-result.md`). Phase 10, Public API + Developer Platform, is next
and is not started. Do not begin a phase without being asked for it.

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

An issued invoice is frozen (ADR 0023). Issuing copies the bill-to party
onto the document and fixes every amount; corrections are credit notes,
never edits. A draft is the only editable state.

The ledger is the truth (ADR 0024). Transactions are append-only and always
positive, the kind decides direction, and an invoice's paid amount is a
cache rebuilt from the rows. One path settles an invoice — `RecordPayment` —
whether the money came from a webhook or an operator. A redirect back from a
gateway proves nothing; only a verified webhook or a server-to-server answer
moves money.

A model whose columns have database defaults declares them in `$attributes`
too: a default fills the row but leaves the model in memory without the
attribute, and a cast reads that absence as null. It bit the money columns
in Phase 4 and the booleans in Phase 7.

A document number belongs to the seller, not the buyer (ADR 0025). A
customer is an organization of its own, so `AllocateNumber` resolves the
nearest non-customer ancestor itself; no call site passes the seller. Order,
invoice and credit-note numbers are unique across the installation.

The client area reads what the admin reads. A client screen resolves the
same models through the same services, narrowed by `CurrentCustomer`, and
the presenter — never the query — drops what a customer should not see.
Authorization there is three questions: boundary, ownership, permission. A
record that fails ownership answers 404, never 403.

Vue components read translations through `useTranslations()`, backed by a
JSON block in the document. `FrontEndTranslations` is an allow-list of
dotted paths: adding `t('group.key')` to a component means adding its path
there. A language file holds operator vocabulary next to customer
vocabulary, and shipping a whole file publishes the first kind.

Provisioning is idempotent and failure is a state (ADR 0026). An adapter
takes a value object and returns one — it never touches the database. The
external id is written the moment a provider returns it; `already_done` is a
success, because that is what makes a retry safe; a run that cannot succeed
leaves the service in `failed` with a reason, never in `provisioning`. The
remote call never happens inside a transaction, and a service is a copy of
what was bought, like an order line.

Contexts meet through events (ADR 0027). `TransitionOrder` announces
`OrderPaid`; provisioning subscribes. Events carry identifiers rather than
models, listeners are registered explicitly in a service provider, a
listener only writes rows and dispatches jobs, and nothing is dispatched
from inside a transaction.

Queued jobs go on named queues. A new queue has to be added to the Horizon
supervisor in `config/horizon.php`, or its jobs sit in Redis and the failure
is silent.

A domain is not a service (ADR 0028), and availability is three-valued. A
registry that did not answer has **not** said a name is free; `unknown` is
preserved from the adapter all the way to the page. The registrant is never
stored here — the registry holds it — and an EPP transfer code is fetched,
shown once and never written down. `DomainName::parse()` splits at the first
dot and is for hostnames; `parseWithin()` splits against the TLDs on sale
and is the only correct one when the extension is being priced.

Before writing a file under `app/Domain/<Context>/`, check whether it
already exists. Phase 7 overwrote two Phase 3 classes this way.

An event is not a message (ADR 0029). A context raises an event and never
sends a mail. `NotificationEvent` is the complete list of what this
installation can say, `Notifier` is the one place a message leaves the
platform, and every send writes a `notification_deliveries` row — including
`suppressed`, because "they asked us not to" and "it bounced" are different
answers. One channel failing never stops the others and nothing thrown
escapes. Opt-out is applied once, in `ResolveRecipients`, and a
transactional event bypasses it. Wording falls back operator-locale →
operator-default → shipped `lang/`, and a placeholder with no value is left
as itself so a mistake is visible.

A ticket has one clock and one place that moves it (ADR 0030). A department
states its SLA once at normal priority; priority scales it. No SLA is a real
configuration. `TransitionTicket` owns the status and the clock fields —
`ReplyToTicket` decides which status a reply implies and then asks for it,
because two places that can set `resolved_at` is one too many.

`OrganizationContext::withoutBoundary()` must **execute** the query inside
the callback. A global scope is applied when a query runs, not when it is
built, so a builder handed back out of the callback is scoped again by the
time anyone calls `get()` on it — and the symptom is an empty result with no
error. `SellerDepartments` is the worked example: a department belongs to
the seller, and a seller is never inside its customer's own subtree.

Never `use` a global class in a Pest test file (`use RuntimeException;`).
The suite passes and the process still exits 1, with nothing printed. Write
`RuntimeException` directly — a test file has no namespace, so it already
resolves.

A role gets the permissions of a phase when that phase lands. Phase 8 found
`support` holding none of the support permissions, which made the role
called Support unable to open a ticket.

A run is a record, and time is not a trigger (ADR 0031). Every automation
task asks a question about rows — "which services are past due and not
suspended" — never about the clock, so a scheduler that was down for three
days catches up instead of skipping three days permanently. The guard
against repeating is always state: `renewal_invoiced_through`, a row in
`invoice_dunning_steps` with a unique index, the invoice's own status.
`RecordedRun` wraps every task, so a run cannot forget to write its record;
a run that changed nothing is still written. One row failing never stops a
sweep, and `completed` means the run finished, not that every row
succeeded. A new task needs two tests: run it twice and assert the second
changed nothing, make one row fail and assert the rest completed.

An operation is visible before it finishes (ADR 0032). `WatchedDispatch`
opens the `operations` row **before** handing the job to the queue, because
an operation that never reaches a worker is the failure nobody sees.
`manual_intervention` is a real end state, not a failed operation with a
note. A retry never resets the attempt counter and resolving never clears
the error.

Dunning is rows an operator edits, not constants. A sequence with no
suspend step is a valid configuration. A step's action failing does not
record the step as run, so the next sweep tries again — a platform that
marked a service suspended without suspending anything would be lying to
its own operator.

A health check never returns a configuration value — not a DSN, not a host,
not a key prefix — and a test asserts it. Three states, because `degraded`
is what a queue with a thousand waiting jobs is. The scheduler heartbeat
lives in `platform_state` rather than the cache: one that vanishes on a
Redis restart cries wolf after every deploy.

Maintenance mode is the operator's switch, not `php artisan down`. It
closes the storefront and the client area, leaves the admin area open, and
turns itself off once its window passes.

`ResolveSeller` is the one place that answers "who sells to this
organization". Document numbering, support departments and dunning all need
it, and three private copies of a boundary escape is three chances to write
one without the narrowing that makes it safe.

A `static fn` cannot reach `$this`. Phase 9 shipped one in a controller's
`->map()` and every test passed, because no test loaded that page. If a
screen has no feature test that renders it, it has not been tested.
