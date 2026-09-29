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
php artisan module:list             # what is on disk, and its state
php artisan module:make <slug>      # scaffold a package that compiles
php artisan db:seed                # provider org, permissions, system roles
php artisan identity:create-owner  # the first staff account
php artisan migrate --env=testing
```

Tests run against **MariaDB**, never SQLite — start it with
`docker compose up -d db redis` first. See
`docs/operations/local-development.md`.

## Frontend work

Any change to `resources/js`, `resources/css` or `themes/` follows the project
skills in `.claude/skills/`: `enterprise-design-system` (visual language),
`enterprise-cms-ux` (page structure, actions, states), `frontend-architecture`
(which primitive to use), `accessibility`, `responsive-enterprise-ui`, and
`visual-quality-review` — which must be run in the browser before a frontend
task is called done. Reference screens: `Admin/Dashboard`,
`Admin/Customers/Index`, `Admin/Customers/Show`. Status words map to colours
only in `resources/js/status.ts` (ADR 0048). Compilation is not completion.

Redesigning an existing screen: pick the next unticked page in
`docs/design/propagation.md`, convert it with
`.claude/skills/frontend-architecture/page-recipes.md` (keep the page's
script logic and props), put new strings in `lang/en` + `lang/tr` (the `ui`
group is already published to the browser), run the gates and
`visual-quality-review`, then tick the page.

## Definition of Done

Migrations reviewed and reversible where practical; policies and permissions
exist; validation exists; tests cover success, failure, idempotency and
organization isolation; audit records exist for sensitive actions;
translations exist; API docs updated; secrets redacted; UI handles loading,
empty and error states; indexes match real access patterns; all gates pass;
operational docs updated. No `TODO` silently defers an acceptance criterion.

## Current state

**Phases 0 to 17 are complete** (`docs/architecture/phase-0-result.md` through
`phase-17-result.md`). The V2 addendum's roadmap (handoff §22) is finished.

**Handoff #2 has begun.** `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` is
planned in `docs/architecture/advanced-operations-plan.md` — its §30 required that
plan before any of it was built — and its phases are lettered. **Phases A to H
are complete** (`phase-a-result.md`, `phase-b-result.md`, `phase-c-plan.md`,
`phase-d-result.md`, `phase-e-result.md`, `phase-f-result.md`,
`phase-g-result.md`, `phase-h-result.md`); **I has begun** and J is not
started.

Two things are deliberately unproven and the owner deferred them: **the provider
adapters (Stripe, cPanel, Namecheap) have never talked to their real
providers** — their request shapes, retries and error handling are tested
against faked HTTP, which proves the code and not the integration — and **no
screen has been driven in a browser**. `docs/operations/release-checklist.md`
says the first real deployment must treat each adapter as unproven.

Provider adapters (Stripe, cPanel, Namecheap) are deliberately last, by the
owner's instruction. None has ever talked to its real provider. The same is now
true of handoff #2's twenty-three adapter families: `FileProbe` reads a file, and
no real monitoring system has ever answered this code.

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

Strict mode only reports a lazy load when the query returned **more than
one row** (`Builder::hydrate()`, Laravel's N+1 heuristic). A screen that is
correct with one record and throws with two passes every test written
against a single fixture and fails the first time an operator opens it.
`tests/Feature/LazyLoadingTest.php` creates at least two of everything on
purpose; keep it that way.

`Customer::displayName()` falls back to `primaryContact`, then to
`organization`, when there is no company or legal name. So **any query whose
rows render a customer name eager-loads `Customer::displayNameWith()`** —
`Customer::displayNameWith('customer')` when the customer hangs off the row.
Never `with('customer')` alone, and never a partial select on it: a column
the caller did not anticipate is the same exception by another route. A
customer created at checkout by an individual has no company name, which is
why this only ever breaks on real data. It has now been found twice, on
orders and invoices in Phase 10 and on Manage users in Phase 11, which is
what the helper exists to stop.

A presenter that recurses into a relation must stop at the depth the data
actually has. An order line is a product with its addons under it — one
level — and `item()` takes `withChildren` rather than recursing blindly
into a third level nothing loads.

The API is a surface, not a system (ADR 0033). `AuthenticateApiToken` puts
the token's contact on the `client` guard and calls the same boundary
middleware a browser request uses, so `CurrentActor`, `CurrentCustomer` and
every policy behave identically. A controller under `Api/V1` calls the same
application use case a portal controller calls; if no use case exists, the
endpoint is not the place to write one. A scope is a fourth question after
boundary, ownership and permission, and it **only narrows** — each declares
its required permissions on `ApiScope`, and a token whose holder lacks them
reaches nothing. `:write` never implies `:read`. A token carrying `*` from
before Phase 10 carries nothing.

A write is replayable, and so is a delivery (ADR 0034). `Idempotency-Key`
stores the response and replays it verbatim; the same key with a different
body is a 409; a **refused** write releases the key, because a client that
fixed its payload has to be able to retry. That rule is a status check, not
a try/catch: Laravel's pipeline turns a validation failure into a 422
response before any middleware sees it. Outbound, a webhook delivery stores
its payload, keeps one `event_id` across attempts and redeliveries, signs
`timestamp.rawBody`, never follows a redirect, and retries from a row
rather than a delayed job.

Middleware that must see a refused request records in `terminate()`, not
around `$next`. The exception handler that turns a thrown refusal into a
401 sits outside every route middleware.

`docs/api/openapi.json` is generated by `php artisan platform:openapi` and
a test runs `--check`. Never edit it by hand.

A policy with no method for the ability is a denial. `authorize('create',
Ticket::class)` fell through to false for everybody including the owner,
because `TicketPolicy` had no `create()`; `OrderPolicy` had the same gap.
Nothing caught either, because no test had ever rendered the screen. When
a controller authorizes an ability, check the policy actually answers it.

`$data['key']` after a `nullable` rule is a 500. `validate()` returns only
the keys that were submitted, so a field the form left empty is absent
rather than null — read it with `??`. And a rule naming a table names the
**table**: `exists:departments,id` was wrong for a model whose table is
`support_departments`, and could not fire while the screen answered 403.

A module may execute, and enabling is the moment it does (ADR 0038).
`ModuleCatalogue` reads JSON and never loads a class; `ModuleLoader` is the
only place a module's PHP enters the process; installing writes a row and
runs nothing. A migration is a class the module author wrote, so running one
is running the package — which is why migrations are in `EnableModule` and
not in `InstallModule`. What a module registered is written on the row, so
uninstall can refuse **without loading the package** it is being asked to
remove. Anything thrown leaves the module `failed` with the reason.

The SDK is `app/Domain`, versioned apart from the product (ADR 0039).
`Sdk::VERSION` moves when the extension surface moves: adding a method with
a default in `BaseModule` is a minor bump, changing anything a module
implements is a major one, and a major bump makes every module refuse until
its author has looked. `app/Domain` is public API now — a change there is a
change somebody else's package can see. `BaseModule` is the one class there
that is not `final`, because it exists to be subclassed by code this
repository does not contain.

A module's config schema is declared in **both** the manifest and
`configSchema()`, and that is not duplication. The interface can only be
asked of a *running* module, and a module with a required setting cannot run
until it is configured — the manifest closes that deadlock, and a running
module's own schema wins once it is running. Registration is inspected twice
for the same reason: before `boot()` to refuse a wrong type early, and after
it because a module works out what it provides from its configuration.

Composer's autoloader caches misses. Anything that asks for a module's class
name before the module is enabled leaves it in `missingClasses` and no
`addPsr4` afterwards rescues it, so `ModuleLoader` registers its own
autoloader, prepended, bounded to each module's `src`. The symptom was a
module that loaded alone and vanished when the architecture tests ran first.

`modules/example/status-board` is the worked example and
`tests/Feature/ExampleModuleTest.php` drives it. Nothing in core references
it: if the SDK stops working from outside core, that file fails. A worked
example no test runs is an example that rots.

A permission's wording lives in one array keyed by slug, read by
`PermissionNames` — never through `__()`. A slug has dots in it, so
`__('access.permissions.crm.customers.view.label')` asks the translator to
walk five levels of nesting, returns the key, and reads as "untranslated"
while looking like it works. A new permission needs a label and a
description in `lang/en` and `lang/tr`; `AccessControlTest` fails without
them.

The admin menu is across the top, grouped the way WHMCS groups it —
Dashboard, Clients, Orders, Billing, Support, Utilities, Setup — because
the operators who will run this have spent years there. Services and
Domains keep their own menus. `AdminLayout.test.ts` covers the map, the
order and the dropdown behaviour; a new destination goes in the map and in
that test.

It became a left rail for a while and is back across the top (2026-09-26), in
the two bars DESIGN.md names: `global-nav` (black, 44px, 12px links, and
translucent, because a bar that never scrolls away has to let the page show
through) and `sub-nav-frosted` under it carrying the breadcrumb. The group
order is the handoff's sections flattened — Clients, Orders, Billing,
Infrastructure, Support, Utilities — which is how WHMCS's words and §3's
structure are both true.

**A destination with children is a heading and its rows.** The rail hid each
filtered list behind a second press; the dropdown lists them under their
parent's name, because a menu that is already open has nothing to gain by
hiding half of itself — and the parent's own href was always the first
child's. `openItem` is gone with it.

The dropdown is teleported and `fixed` for a new reason as well as the old
one: `backdrop-filter` creates a containing block, so a panel left inside the
translucent bar would be clipped by the very thing that makes it glass.

An addon is not a service and not just an order line (ADR 0035). A
`service_addons` row has its own price, cycle, renewal date and status, and
follows the service it hangs off — `TransitionService` is the one place that
moves both. `AddonStatus` is deliberately smaller than `ServiceStatus`: an
addon is never provisioned on its own here, so `provisioning` and `failed`
would be members nothing can set. A `cancel_pending` addon follows nothing
but termination, because suspending and resuming the service must not undo
"they asked to stop paying for this". Addons are written on **every**
fulfilment run, not only the one that created the service, and read from
`OrderItem` directly — `$order->items` excludes children on purpose, and an
addon line is exactly a child.

A brand is a row, not a config value (ADR 0036). `CurrentBrand` answers
whose brand applies per surface, and the client area resolves the
**seller's** — a customer is an organization here, so asking for "the
current organization's brand" would show them their own name on their own
invoices. A brand inherits field by field up the organization path, and a
null and an empty string both mean "ask my parent", or somebody who clears
a field can never undo it. Nothing a brand holds may be a secret, and a
test asserts the shape: a brand is printed by templates a theme author
wrote.

A theme is a package and may not execute (ADR 0037). Precedence is
**resolution, not merging** — installation override → child → parent →
core, registered as view paths in order, so no controller changes.
**Settings merge; templates do not.** Raw PHP in a theme template is
refused at install, by file. A theme that needs behaviour is a module, not
a theme. `themes/storefront/core` is itself a theme, which is what keeps
the chain honest.

An entitlement is a seam, not a policy. `Entitlements` allows everything by
default, because a gate whose default is deny turns an unreachable licence
API into an outage. It is asked at **render** as well as on save: a lapsed
licence shows the vendor mark again rather than leaving it hidden forever.

`attributes()` is reserved on `FormRequest`. A request class that overrides
it for its own purposes breaks validation on that request, quietly.

The palette is navy, and it is a token list rather than a set of classes.
Four surfaces: `surface` is the page, `chrome` is the header and footer
(what the palette calls the sidebar), `surface-raised` is a card,
`surface-sunken` is the other surface — **and that last one inverts**, being
lighter than a card in dark mode and darker in light, because the dark page
is already the darkest thing on screen. `accent` is the primary blue and is
what a brand overrides; `highlight` is the one second hue (cyan) and marks
where you are rather than what to click. `info` and `automation` exist
because "this ran" is neither a success nor a warning.

A brand overrides `--color-accent` and the platform **derives** the hover
and subtle states from it. Overriding one without the others was half a
rebrand: a pink button that hovered to platform blue.

A panel that must escape its container is `useAnchoredPanel`, and there is
one of it. A container with `overflow-y-auto` clips **horizontally** too, so
a flyout positioned `absolute` inside the sidebar's scrolling nav opened
inside a 72px bar; the table row menus had the identical bug from the
identical cause. `fixed`, against the trigger's own rectangle, teleported to
the body, is the only placement no ancestor's overflow or transform can cut
off. A new dropdown uses the composable rather than writing `absolute` and
finding out later.

A `watch` that installs behaviour must be `immediate` when the state it
watches can start true. `AppDrawer` mounted already open had no Escape
listener and never took focus, and nothing said so.

A drawer's record is an `Inertia::optional` prop on the route the list
already uses, not an endpoint of its own: nothing is built on an ordinary
page load, and asking for the one prop by name builds one record instead of
re-running the list. `assertInertia` cannot read the answer — it pulls the
page object out of a rendered view, and a partial reload renders none — so a
partial is asserted with `assertJsonPath('props.…')`, and the request needs
`X-Inertia`, the real asset version (or Inertia answers 409) and both
`X-Inertia-Partial-*` headers.

A bulk endpoint takes ids from a browser, so the policy is asked about
**every row**, a row the action cannot apply to is skipped rather than
refused, one row failing never stops the rest, and the answer is three
numbers rather than the word "done". An id the operator may not touch is
left out silently: a 403 confirms it exists.

Column visibility is `localStorage` and a saved view is not. Which columns
fit is a fact about the window somebody is looking at; a named set of
filters is a question about the data and belongs on the server. A stored
empty list means "they turned everything on", which is not the same as never
having been asked.

`array_values($collection->all())`, not `$collection->values()->all()`, when
a method promises `list<…>`: only the first narrows the type for PHPStan.

`audit_logs` names its subject `target_id` and `target_type`, not
`subject_id`.

A reseller is an organization and the reseller area is the admin area,
narrowed. `resellers.administer` is a **gate, not a permission**, and it
cannot be one: a reseller's own Administrator holds every staff permission
by design, so a permission for it would let a reseller set their own margins
and write their own balance. The gate asks which organization somebody
belongs to — only one whose `permittedChildTypes()` includes `Reseller` — and
still wants `organizations.manage` on top.

A reseller's balance with the provider is a ledger, positive means they
**hold** and negative means they **owe**, and the running balance is written
onto each row under a lock so two payments recorded at once cannot both build
on the same previous one. `ResellerLedgerKind::Withdrawal` is the decrease
that `Credit` is the increase of; there is no `Adjustment`, because a kind
that decides direction cannot be a word that does not.

Attribution in the reseller reports is a join on `organizations.parent_id`,
and it works **because there is one level of resale**: the organization that
owns an order is a customer, so its parent is the seller. Sub-resellers would
make it a recursive walk of `path`.

`tests/Feature/ResellerScreenAuditTest.php` walks the router and drives every
parameterless admin `GET` as a reseller's Administrator: 200, 403 or 404 and
nothing else, then greps every 200 body for three records the provider owns.
A screen added in a later phase joins it the day it is routed. Two guards
keep it honest — one asserts the filter still matches screens, the other that
the leak check examined bodies — because an audit that checks nothing passes.

A lapsed licence is not an outage (ADR 0041). The installation verifies an
Ed25519 token locally against a public key in the distribution — it can prove
a licence and cannot mint one — and the signature covers the **raw payload
bytes**, because a signature over re-encoded JSON fails on a different PHP
version. `LicenceUnreachable` and `LicenceRefused` are separate types and
nothing catches them together: "the vendor is broken" and "your licence is
revoked" must never be the same outcome. Grace counts from the token's
`heartbeat_by`, not from last contact. The token lists **exclusions**, so a
feature added after a token was minted is allowed rather than silently
switched off for every existing licence. When everything lapses, the vendor
mark comes back and nothing else changes.

The installation UUID lives in `platform_state` on purpose: restoring a
production backup into a second environment then produces two installations
claiming one identity, which is exactly the anomaly the licence server should
see. The licence key is the one secret in that context — write-only on the
screen, in the redaction list under both spellings, and three tests assert it
reaches neither an audit row, a health report nor a rendered page.

The vendor's licence API is a separate application this repository does not
contain. `docs/licensing/api.md` is the contract; `Tests\Support\FakeLicenceClient`
is what the tests drive.

An import writes rows and dispatches nothing (ADR 0042). It is a copy of
history, and it is the **one write path in this product not covered by the
use-case rules** — so a phase that adds a required column to invoices has to
add it to `InvoiceMapper` too, and nothing but a test will catch that.
`IssueInvoice` would renumber a document the customer has on paper;
`OrderPaid` for two years of history would provision two years of services and
email everybody.

`import_mappings` is one unique index that buys three requirements: duplicate
protection, resumability and "which of my old clients came across". A dry run
is the same code path with one flag read in `ImportWriter`, and it writes no
mappings either — or the live run would skip every row. `renewal_invoiced_through`
is set on every imported service and domain, because without it the first
nightly renewal sweep after a migration invoices every customer again.

Imported records are written into states that assert nothing is in flight: a
service is never `provisioning`, a domain never `registering`, an invoice never
`draft`, and every ticket is closed. `0000-00-00` is read as no date, because
Carbon parses it into the year zero without complaint. Exactly one hard parent
exists — a record belongs to a customer or to nobody; products are not a hard
parent of services, and a test found that listing the soft ones refused
"customers and services only".

`WhmcsImportSource` has never read a real WHMCS database. Every column it
reads is in one constant and `check()` verifies all of them before anything is
written.

Every monetary answer is `MoneyByCurrency` — a list, never a number. There is
no rate anywhere in this product, so a total across currencies is a figure that
means nothing and it is the figure somebody would quote.

MRR is a snapshot of active services divided down to a month by integer
division; **ARR is twelve times that and the card says so**. Printing one and
calling it the other is the classic reporting lie. A one-time line is skipped
rather than counted as zero, or a setup fee lands inside a recurring figure.

Aging is measured from the **due date** and by what is **outstanding**: an
invoice issued ninety days ago on sixty-day terms is thirty days overdue, and a
partially paid one ages at its remainder. An invoice with no due date gets its
own bucket, because there is no honest arithmetic to do with a missing date.

Money in comes from the ledger, not from invoices — an invoice's date is when it
was issued, so a chart built on it is a chart of intent. Gateway revenue is net
of refunds. Product revenue comes from the **services**, never from invoice
lines: a line copies a description (ADR 0021), so grouping by it merges two
products renamed the same thing and splits one renamed last March.

Security headers are **global** middleware, not on the web group, and a test is
why: a route-model binding failure throws inside the router's pipeline, so the
response is rendered outside every route middleware — a 404 went out with no
CSP, and an error page is exactly where an unescaped value ends up. The CSP is
enforced rather than report-only, and `script-src 'self'` has no exceptions
because Inertia's page object is a `data-` attribute and the translations block
is `application/json`.

`SafeUrl` is checked **immediately before a request**, never at save time: DNS
can change in between and that is the whole SSRF technique. An unresolvable host
is **allowed through** — refusing it would mark a webhook delivery permanently
unsafe, so a customer's DNS blip would silently end their deliveries. No refusal
ever echoes the URL back.

`auth.recent` asks for a password again before eight irreversible actions, in a
fifteen-minute window. **`owner` runs before it** — with the check inside the
controller, a staff member who may not touch the Licence screen was asked to
confirm a password and then refused, which is rude and a small oracle. Two
existing tests caught it by expecting 403 and getting 302.

Concurrency is tested **at the guard**, not by racing threads: a flaky test is
worse than none because it gets retried until it passes. `increment()` is safe —
it is atomic in SQL — and the lost update is read-modify-write in PHP; a money
column that caches rows is recomputed from the rows, and a counter uses the
atomic increment. Both are pinned by tests.

Accessibility is tests over the primitives, not a document — nobody audits forty
screens twice a year. One of them found that `info` and `healthy` shared `●`,
which made two states identical in greyscale inside the component that enforces
"status is never colour alone".

There is no backup button and there will not be one: a PHP process cannot take a
consistent snapshot, and one that produced an inconsistent snapshot would be
worse than none because somebody would rely on it. `docs/operations/` holds the
boundaries, the restore order, the runbooks and the release checklist.

The admin shell's density was reset in Phase 11: the page and its cards are
far enough apart in lightness to read as two surfaces, tables use small-cap
headers and a hover row, badges carry a tint of their own tone mixed from
the semantic token, and counts an operator opens a screen for are `AppStat`
cards that filter when pressed. Check a visual change against the built
stylesheet in both themes rather than inferring it from class names.

The Resource Graph stores identity and relationships and never the facts the
owning table holds (ADR 0043). A node carries a kind, a key, a cached label and a
pointer at the row it *is*; the price lives on the service. Edges are append-only
with a closing timestamp, like the ledger, so "who had this IP in March" is a
`where` rather than a feature. **An edge belongs to its container's
organization**, which makes direction a privacy decision: containment points
downward so the boundary hides the container from the contained, and a customer
cannot walk up from their service to the server. `ResourceGraph::attach()` takes
`container` and `contained` as named arguments for that reason, and refuses an
edge whose ends are in different subtrees.

The graph's traversal is **one query per level, not a recursive CTE**. Hand-written
SQL carries no global scope, and an unscoped lookup is the one bug this platform
will not trade four round trips for. Depth is bounded at twelve because discovered
data contains cycles.

`ResourceKind` is an open vocabulary and `Relation` is a closed one. Core cannot
know every noun a module will discover; it must know which edges mean "inside",
because an unrecognised relation is an edge the impact query would silently skip —
and a screen that silently skips an edge tells an operator an outage affects
nobody.

An adapter declares what it *can* do and the row says what it *may*.
`resource_adapters.writes_enabled` defaults to false, turning it on is audited with
the capabilities named one by one, and the registry **narrows** rather than
refusing — a capability the row has not enabled is absent, so a screen cannot offer
a button the platform would then refuse. The rule lives in one place
(`ResourceAdapter::narrow()`) because it briefly lived in two and they disagreed
about a disabled adapter.

Telemetry keeps the present, never the series (§14). One row per node and metric,
upserted; Prometheus and Zabbix keep the history. A metric name core has no
`MetricKind` for is counted and dropped, because a table that accepts any name is
a time-series database nobody sized. A unit from the wrong dimension is a refusal,
not a conversion — but a unit the source wrote into the *name* (`cpu_percent`,
`memory_used_mb`) is read, because that is the opposite of guessing. The worked
example found that one within a minute of first running.

`value` on a metric is a `double` and that is not the money rule bending:
telemetry is a measurement and money is an amount. No monetary value is ever
stored in the graph; `ImpactSummary` reads `services.recurring_minor` and answers
in `MoneyByCurrency`.

Never write a translated sentence into a database column. `health_message` stays
null for staleness: a message stored in the language of whichever scheduler run
wrote it is a message the next operator cannot read, and the screen already has
`sampled_at`.

A new extension point has to be added to `InspectModule::declared()` as well as to
`ExtensionPoint`, or the module's row says it registered nothing — and uninstall
then cannot refuse without loading the package it is being asked to remove.

Setup is a page, not a dropdown (`/admin/apps`), and it is **not in the rail** —
it hangs off the spanner in the topbar, with the things somebody configures once
rather than works in. The owner-only screens are a section on it: Modules,
Servers, Licence, Import. The page itself opens to any staff member holding one
of the setup permissions; the owner-only section is simply absent for everyone
else, because the gate is on the controllers behind it rather than on the hub.
The Setup rows stay in the nav map although nothing draws them — the command
palette is built from that map, and `hidden` on a group is what keeps rows out
of the rail and in ⌘K.

**Connect is a permission, not the owner-only gate.** `infrastructure.connect`,
held by Support, and it lives in Utilities. The whole point of the screen is that
a support agent gets a short-lived session the panel issued instead of being
handed a root password or an API key — which only works if the people who need it
can reach it. The token never leaves the server, and `ConnectScreenTest` asserts
it reaches neither the page nor the props.

**`owner` goes on the route group, above `auth.recent`.** Phase 17 learned it on
the Licence screen and the fleet and module routes had the same gap: with the
check only inside the controller, somebody who may not touch the screen was asked
to confirm their password and *then* refused. Rude, and a small oracle.

**A path a page names is a promise, and `AdminActionRoutesTest` is what checks
them.** The fleet screen moved behind the Apps door and went on posting to
`/admin/infrastructure/…` for several phases: adding a server, editing one,
deleting one, adding a group and testing a connection all answered 404 and looked
like nothing happening. Nothing caught it, because the screen had a test and the
test *rendered* the screen. Phase 9's rule was "a screen with no test that renders
it has not been tested"; this is the one after it — **a screen whose actions no
test performs has not been tested either.**

That rule was then applied to the whole admin area, by recording the route names
the suite actually requests and diffing them against the router: **35 of the 127
admin write endpoints had never been called by any test.** They are all covered
now (`ServerFleetTest`, `ModuleScreenTest`, `ConnectScreenTest`,
`TodoAndRepliesScreenTest`, `ContentScreenTest`, `CatalogWriteTest`,
`AdminWriteGapsTest`, `StaffPasswordResetTest`), and driving them found three
more bugs that rendering never would:

- **`exists:departments,id` on the predefined-replies screen**, where the table is
  `support_departments`. Choosing a department could never validate, so a reply
  could only ever be saved for *every* department. The identical mistake had been
  found once before, on the ticket screen — which is why a rule naming a table
  deserves a second look every time.
- **Every refusal on the Modules screen was a 500 page.** `InvalidModule` carries
  a sentence naming the module, the versions and the range, and nothing caught it:
  an unconfigured module, one built against another SDK and a downgrade all ended
  in a server error. `ModuleController::refusable()` turns it into an error on the
  form; anything else still reaches the handler, because anything else is a bug.
- **The staff password reset had no test at all** — the only way back into an
  installation whose operator is locked out, and it writes a password.

When adding a screen, drive its buttons. Rendering it proves the props; only a
request proves the payload the form sends is the payload the controller wants.

Tax is rows an operator edits, not a country in the code (ADR 0045). Core ships
no rates, names no jurisdiction and charges nothing until a rule exists — the
empty state says so. A rate is `rate_ppm`, **parts per million**: Quebec's QST is
9.975%, which basis points cannot express and `(int) (9.975 * 10000)` renders as
99749. The percentage an operator typed becomes that integer **once**, in
`TaxRuleRequest`, validated as a string against a regex rather than as `numeric`,
which would accept `1e2`. Matching is most-specific-place-wins and **one rule per
level**, or a state rate and a national rate both fire and the customer pays
twice. Two levels with a `compound` flag, because Quebec's QST is charged on the
amount plus GST while British Columbia's PST is not, and one level cannot say
both. Rules belong to the **seller** via `ResolveSeller`. The screen is
owner-only, on `/admin/apps`: `tax.manage` could never work, since an
Administrator holds every staff permission by design and would set their own VAT
rate. Its Try it panel calls `app(TaxCalculator::class)` rather than
reimplementing the arithmetic — a preview that agreed with a second
implementation and disagreed with the invoice is worse than none. Changing a rate
never touches an issued invoice, and a test asserts it.

A `*/` inside a `/* … */` block comment closes it. `FrontEndTranslations` gained
a comment mentioning `lang/*/tax.php`, the rest of the sentence became PHP, and
the class stopped parsing — which 500'd **every rendered page in the product**.
Six of the seven new screen tests passed while it was broken, because nothing
that does not render a document touches that class. A test that renders any page
is a guard on the document chrome, so `assertOk()` on a real render belongs in a
new screen test before anything about the screen's own data.

A document sequence may restart, and that is a legal requirement rather than a
preference: an unbroken run from INV-000001 forever is not an acceptable invoice
book in Turkey, Italy, Spain, Portugal or Poland. `number_sequences.reset_period`
plus `period_key` express it, and the key is **stored and compared**, never
derived from `updated_at` — a timestamp cannot tell "nobody invoiced in January"
from "already reset in January", and the second must not reset twice. The reset
happens **inside `AllocateNumber`, under the same row lock as the increment**: two
documents raised in the same second must not both decide they are the one that
resets, and a scheduled task that reset sequences at midnight would be a task
that *has* to run — a reset that did not happen is a duplicate invoice number.
Turning the reset on adopts the current period instead of restarting, and
switching it off does not cause one last restart from the stale key; both are
somebody's invoice numbers and both are pinned by tests. `next_value` is writable
because an installation migrating from another panel has to continue its book at
10421, and lowering it is audited rather than refused — the next failure is a
unique-index violation and the audit row is what explains it.

A late fee is a new invoice (ADR 0046), answering a question `DunningAction` left
open on purpose in Phase 9. The invoice the fee is about is frozen so it cannot
grow a line, and a line on the *next* invoice arrives weeks after the behaviour it
exists to discourage. It is a **dunning step**, so the timing is the step's
`offset_days` and `invoice_dunning_steps` is what stops it charging thirty times;
it is a percentage of what is **outstanding**, not of the total; `invoices.is_late_fee`
keeps the fee invoice out of the sweep, or a fee earns a fee nightly and a suspend
step takes a server down over three euros of interest; nothing to charge returns
null and the step is still recorded, because "there was nothing to charge" is an
answer; and the customer's **suspension** preference does not apply to it — "never
suspend us" is about service, not about owing money.

`billing_settings` is a seller's terms: `due_days`, `late_fee_rate_ppm`,
`late_fee_label`, `document_note`. A seller with no row gets `config('platform.billing')`
and **no row is written on read** — that would be terms nobody agreed to, frozen
where the next deploy could not reach them. The document note is copied onto
`invoices.terms` at issue and never read at render, for the same reason the
bill-to party is (ADR 0023).

Never memoise a settings lookup that can miss. `BillingSettings` briefly cached
per seller, which cached the **miss** — a model saying "nobody has stated these" —
and held it across a save: the form redirected, the screen re-rendered from the
cached default, and it read as a settings page that did not save. A single indexed
lookup per call is the cheaper mistake. The test that caught it asserted that a
banner disappears after saving, which is why a sentence shown to an operator is
worth a test of its own.

A setting that is stored and read by nothing is worse than a setting that is
absent. The tax screen shipped with three of them and each was a different size
of lie: `prices_include_tax` silently added VAT on top of prices that already
contained it, `tax_id_label` left every form saying the wrong word, and
`require_tax_id_for_business` did nothing at all. After adding a column to a
settings screen, grep for a reader before calling it done.

Tax may be **inside** a price. `TaxResult::$included` says so, and it lives on the
answer rather than on `TaxableSupply` because only the calculator knows — whether
a catalog is gross is the seller's setting, and a module's calculator returns
false and behaves exactly as it always did. The net is reached by **integer
division** (`gross x 1e6 / (1e6 + effective_ppm)`), a compounding level
contributes `r2 x (1 + r1)`, and because that division loses a fraction of a part
per million the components are then **reconciled against the gross** with
`Money::allocate()` — the price the customer was shown is the fixed point, and
`allocate` loses no cent. `PriceCart` subtracts instead of adding and its subtotal
carries the net, which keeps `subtotal + setup - discount + tax = total` true
either way; that is why `PlaceOrder`, `CreateInvoiceFromOrder` and every presenter
needed no change.

**A business is a company name, not a tax id.** `Customer::isBusiness()`. It was
`tax_id !== ''` in both places that built a supply, which is circular: it made an
individual who typed a tax id a business, a company that had not given one an
individual, `TaxCustomerKind::Business` mean "typed a tax id", and "a business
must state a tax id" impossible to ever fire. Reverse charge is unaffected — it
asks for both, a business *and* an id to charge it to.

`TaxIdentity` is the one place that answers what a tax id is called and whether a
business must give one; `AsksForATaxId` is the one validation rule, used by four
request classes. The default label is never "VAT number": it is Vergi Numarası in
Turkey, an ABN in Australia, a GSTIN in India, and a default that is wrong for
most of the world reads as configured when it is only unset.

`assertSessionHasNoErrors()` alone proves nothing — it passes against a 403 and
against a 404. A test that means "this write succeeded" asserts the redirect too.
One written here was driving a 403 the whole time, because its contact had no
portal role.

Tax is worked out **per line**, not once on the total, and two configurable
things were dead until it was. A rule scoped to products, domains or addons could
never match anything, because the only supply ever built said `TaxAppliesTo::All`
— a column that saved and displayed correctly and did nothing. And `TaxRounding`
had nothing to decide, because there was only ever one calculation to round.
`PriceCart::taxFor()` is where it happens: per line when the seller rounds per
line, and otherwise one calculation **per tax treatment**, so a cart of hosting
is a single calculation exactly as before. A line's taxable amount is its
`lineTotal`, so the parts add up to the taxable total by construction.
`TaxCategory::of()` maps `LineKind` to `TaxAppliesTo` and is a `match` with no
default on purpose: a fifth kind of line must fail to compile rather than land in
whichever rate came first. `CurrentTaxSettings` is the one place that resolves
whose tax settings apply.

The shipped rounding default is **per line**, which is what most panels do.

**The admin area and the storefront have now been driven in a browser**
(`docs/architecture/browser-pass-result.md`). Seven bugs, none of which 1608
tests could see, because a test asserts behaviour and every one of them was about
what a human reads. The provider adapters remain the other standing gap: a
browser proves a screen, and only a real provider proves an adapter.

A GET puts everything it asks into the address bar, the browser history and the
server's access log. The tax preview is an `Inertia::optional` prop and therefore
a GET, and it was sending a customer's **tax id** there. It now sends
`has_tax_id`, because the rules only ever check that one exists — core never
validates a tax id and could not, since that means calling a country's own
service. Before putting a field on a panel that reloads partially, ask what it
would look like in a log line.

A button is labelled with what it does, not with the heading above it. Two save
buttons said "How tax behaves" and "Billing terms". And an `AppButton` that is a
direct child of a `flex flex-col` stretches to the full width of the panel — wrap
it in a plain `<div>`.

**A dialog's dismiss is called Cancel**, so an action that is itself called Cancel
puts two buttons starting with the same word side by side — and the one that
backs out and the one that goes through look alike at a glance. The invoice
draft's action is `Discard draft…` for that reason. The Turkish never had the
problem: `ui.confirm.cancel` is *Vazgeç*. Check the confirmation, not only the
button that opens it.

**A redesign reads the props, not the old template.** `Admin/Invoices/Show.vue`
printed a payment's state as muted prose although the controller had been
sending the raw `status` alongside the label the whole time — the TypeScript
interface simply never declared it, so nothing said it was there. Driving it
through `statusTone()` then found `partially_refunded` missing from
`status.ts`, which had been drawing the unknown mark (○) on a real state. When
converting a screen, diff its props interface against the controller's payload
before deciding a fact cannot be shown properly.

The order screen had the mirror image of it: `OrderController` sent the
invoice's **translated label** where the page passed it to `statusTone()`,
which matched `unpaid` only because that is what the English label lowercases
to. In Turkish it fell through to `unknown` and drew ○ on a real state. A
status crossing to the browser is **two fields** — `status` for the tone and
`statusLabel` for the word — and anything sending one of them is either
untranslated or untoned. Driving the same screens through `statusTone()` also
found `allow`, `review` and `deny` missing from `status.ts`, which is where a
risk decision's tone belongs rather than in an `AppBadge` on one page.

**A page prop must not be named like a shared one.** `SettingsController` sent
`brand` — the raw, uninherited row — and `HandleInertiaRequests` shares a prop
of the same name that the shell reads. The page's one won, for that screen
only, so `/admin/settings` printed a copyright line with no company in it and
handed `useBranding()` an object full of nulls, which took the sidebar's brand
mark with it. It is now `brandFields`. Nothing catches this: both props are
valid, the page works, and the damage is in the chrome around it — which is
another reason a screen is not finished until it has been looked at.

**A hand-rolled strip of figures is a `MetricStrip` that was missing a slot.**
The reports page had four cells at `text-[1.5rem]` — the arbitrary size the
design system bans and the oversized KPI number it bans twice — because money
here is a *list* and the component only took a value. It takes a slot named
after each metric's key now, which is the shape `DescriptionList` already uses.
Reach for the prop or the slot before the `div`.

**The admin nav map is translated, and it keeps its English beside each key.**
`nav('clients', 'Clients')` reads `ui.nav.clients` and falls back to the word
itself. The fallback is not laziness: this map is what the breadcrumb compares
a page heading against, so a label that rendered as `ui.nav.clients` would
break the trail as well as look like a bug — and `AdminLayout.test.ts` mounts
the shell with no translations at all and asserts the English. The section
headings (Business, Operations, …) were the discriminator of a TypeScript
union doing double duty as words on screen; `railSections` carries a `label`
now and the union stays English.

The palette and the theme switch went with it, because a Turkish rail above an
English "Go to a screen, or find a client…" is worse than either. What is still
English is the last crumb when a *page heading* has no translation — the fix
there is the page, not the map.

**A date-only column rendered raw is the one ISO string among localised
ones.** `2026-10-13` beside `24.09.2026 18:28` on the same panel. It has now
been found on the service, domain and transaction screens — anything the
server sends as `Y-m-d` needs `toLocaleDateString()` at the edge, exactly
like a timestamp does.

`__()` returning its own key is the designed symptom of missing wording, and it
only works if somebody looks. Three screens were printing keys at an operator:
`automation.tasks.webhooks.label`, `automation.tasks.licence.label`,
`health.checks.licence`, and Connect was printing `branding.remove_vendor_mark`
because the controller sent `$feature->value` instead of `__($feature->labelKey())`
— the permission-slug trap through a different door. `tests/Feature/VocabularyTest.php`
is the guard now: every automation task, every **registered** health check and
every licensing feature, in both locales. It asks the registry rather than a
hand-written list, because a check added to the container and nowhere else is
exactly the one that goes unnamed.

A customer organization is literally named `Customer` when an individual signs up
with no company — `CreateCustomer` has no personal name to use at that point. So
anything caching an organization's name for a customer caches nothing useful;
`ProjectCoreResources` caches `Customer::displayName()` instead, eager-loaded
with `displayNameWith()`.

When patching files through a script, check the output in the product, not only
the gates. A Python escaping slip wrote `\u00fc` **literally** into two language
files, so a screen went from showing a translation key to showing
`M\u00fc\u015fterinin`, and every gate stayed green — a string is a string.

**A marketplace exists** (`docs/architecture/marketplace-result.md`, ADR 0047).
It is a catalogue the vendor publishes and this repository does not contain —
the same shape as the licence control plane: a contract, `docs/marketplace/api.md`,
`Tests\Support\FakeMarketplaceClient`, and an `Unconfigured…` client so an
installation with no marketplace URL behaves exactly as it always did.

**Four steps, not three.** ADR 0038 said installing is not enabling. Fetching is
now its own audited step before both, because with a package copied into
`modules/` by hand an operator chose the bytes and with a download they chose *a
name in a catalogue*. `fetch` → `install` → `enable`, and only the last one runs
anything.

**Nothing is unpacked before it is proven.** An archive entry is a path the
archive chooses and `../../../.env` is a valid entry name. The order is size →
SHA-256 → Ed25519 over the raw archive bytes → unpack entry by entry into a
staging directory → slug must match what was offered. A refusal at any step
deletes the temp file and writes nothing. The digest catches a truncated mirror
and is **not** the security control: the catalogue that named it could be the
attacker. The packaging key is a different keypair from the licence key, and
there is no flag to skip any of it.

**Every refusal is named separately** (`PackageRefused::digest()`, `::signature()`,
`::unsafePath()`, …). "The download failed" would make a broken mirror and an
attack look identical in an audit log.

`modules.source` records provenance, and the marketplace refuses to replace a
module whose source is `disk` — otherwise a catalogue entry could quietly replace
a hand-installed package. Any directory name built from a **remote answer** is
reduced to `[a-z0-9-]` with no dots: a provider called `..` is a path traversal
assembled from a field somebody else filled in.

**The provider adapters in core are meant to be modules.** `StripeGateway`,
`CpanelModule` and `NamecheapRegistrar` become `gateway-stripe`,
`provisioning-cpanel` and `registrar-namecheap`; the `Manual*` three stay in core
forever, because "an operator does it by hand" must always be a real answer. Not
started — `modules-and-marketplace-plan.md` §2 has the upgrade path, and an
installation crossing that release must find its gateway still working.

A singleton that reads config in its constructor is configuration **frozen at
boot**. `ModuleCatalogue` fixes its root that way and `ModuleServiceProvider::boot()`
builds one, so a test that sets `platform.modules.path` afterwards is pointing a
catalogue at a path it already decided not to use. `forget()` clears the memo,
not the root; `forgetInstance()` is what rebuilds it.

**Official integration modules live in `modules/infracms/`** and are planned in
`docs/architecture/integration-modules-plan.md`, which maps all twenty-four
requested integrations to the seam each needs. Fifteen plug into a contract that
exists today; the rest are blocked on core work and the plan says on exactly
what.

The blocking one worth knowing: **`NotificationChannel` is a closed enum and
`ChannelRegistry` keys on it**, so there is one implementation per channel and no
member for SMS. Netgsm cannot say what it is, `NotificationRecipient` carries
only an email so there would be no address to send to, and Discord, Slack and
Mattermost would each overwrite the last — and core's own `WebhookChannel` with
them. Those four need the channel vocabulary, the registry key and the recipient
address changed first. DNS, IPAM and certificates need contracts that are already
planned as handoff #2 phases; social login and a live-chat embed each need a
decision first (an embed is a CSP decision before it is a module one, and
`script-src 'self'` currently has no exceptions on purpose). **Email templates
are already core** — building a module for them would be a second answer to a
question core answers.

`php artisan platform:package <slug> --key=<path>` builds and signs a package and
prints its catalogue entry. The private key is read from a path and never stored:
not in this repository, not in an environment file, not in a CI variable a build
log can print.

**Never `trim()` a binary key.** A key is random bytes and roughly one in eight
begins or ends with something `trim()` eats — 0x09, 0x0a, 0x0d, 0x20, 0x00.
Trimming first corrupts those keys and leaves the rest working, which looks like
a bad signature, depends on which key was generated, and passes a test with a
random key most of the time. Check the length first, and only trim when it is
text.

`tests/Feature/OfficialModulesTest.php` walks `modules/infracms/`, installs,
configures and enables **every** module, and asserts the registry its manifest
claims actually gained an entry. A module added tomorrow is covered the moment
its directory exists. It exists because the mistake these packages will really
make is not a wrong request shape but **declaring something and wiring
nothing** — and it earned its place immediately, catching a manifest whose
namespace had a doubled backslash.

**SDK 1.3**: `NotificationChannel` gained `Sms` and `Chat`, and
`NotificationRecipient` gained a phone. Minor, because nothing a module
implements changed.

`ChannelRegistry` keys on **the channel plus the implementation's class name**,
not on the channel alone. Keying on the channel meant one provider per channel,
so Discord, Slack and Mattermost each overwrote the last — and would have taken
core's own `WebhookChannel` with them had they registered as webhooks. The class
name is *derived* rather than asked for: adding a `key()` to
`DeliversNotifications` would have been a change to something a module
implements, a major bump, and every module refusing until its author looked
(ADR 0039) — for a value already in hand.

`Notifier` delivers through **every** provider registered for a channel, each
writing its own `notification_deliveries` row, and one failing never stops the
others. That is what an operator wants for chat (the Slack room *and* the Discord
room); for SMS they register one, and registering two is visibly asking to pay
twice.

`NotificationRecipient::isAddressable()` asks **per channel**. An email address
does not make somebody reachable by text and a number does not make them
reachable by mail; the single `email !== null` check answered the wrong question
the moment a second addressed channel existed. Staff carry no number, so nothing
can text them, and that is left honest rather than invented.

An SMS module truncates the **subject**, never the URL: half a link is a message
that cost money and did nothing. And a number that normalises to something
implausible is refused before it is sent rather than paid for.

**A visitor can open their own account** (`/register`, `Auth/Register.vue`). It is
declared in `routes/client.php` and **not** in `routes/auth.php`, although it sits
beside sign-in on the page: that file is registered once per guard, and a
self-registration form on the staff surface would be a way to grant oneself a
staff session. The parent organization comes from `ResolveStorefrontOrganization`
— a visitor registers with whoever's shop they are standing in, and that is not
something a request may state. The customer is created **active**, because
pending would be a trap: `PlaceOrder` refuses a customer who cannot transact,
nothing in this product moves an account out of pending on its own, and
registering creates no obligation there would be anything to withhold against.
An operator who wants to vet new accounts sets `platform.crm.self_registration`
to false, which makes both routes answer 404 and stops the sign-in screen
offering a link to a page that would refuse. The one difference from checkout is
the password: somebody who has bought something claims their account through the
reset flow (which proves the address), and somebody who has bought nothing chose
a password. Neither is ever sent one this platform generated.

**A field in error looked exactly like a field that was fine — on every form in
the product.** `AppInput`, `AppSelect` and `AppTextarea` carried `border-line` in
the base class list and added `border-danger` beside it, so which colour won was
decided by the order Tailwind happened to emit two border-colour utilities in.
The hairline won. Only one of the two classes is ever present now, and
`designSystem.test.ts` asserts the **absence** of the other, because the presence
was never the problem. Two classes that set the same property is not a
precedence question a component may leave to the stylesheet.

`CreateClient` wrote the country code as it was typed. A tax rule is matched on
an ISO code, so a row holding `tr` is a row no rule for `TR` ever fires on — and
the symptom is a correct-looking invoice with no tax on it. It is upper-cased at
the write now, like every other writer of that column. Registration found it,
because a form a visitor fills in is the first one where nobody is in the habit
of using the shift key.

**A browser signed into the admin cannot open the client area**, and the answer
it gets is a bare 404. `CurrentActor` checks guards staff-first and its comment
says a request can only ever be authenticated on one of them — two session keys
in one browser is exactly the case that is not true, and `CurrentCustomer` then
refuses a `StaffUser` the only way it knows how. Nothing here is wrong on its
own; it means a staff member testing the portal has to use a second profile or a
private window. It is worth a decision before somebody debugs it a second time.

**Every `t('…')` a component draws is now checked against what the document
carries** (`tests/Feature/FrontEndTranslationsTest.php`). The allow-list rule
has always been that adding `t('group.key')` means adding its path to
`FrontEndTranslations`, and nothing enforced it: the confirm-password screen —
the one screen whose whole job is to explain *why* before it asks — printed
`identity.auth.confirm_title` as its own heading, because `identity.auth` was
never published. The designed symptom worked perfectly and nobody looked. The
test scans `resources/js` for literal calls, skips the ones that pass a
fallback (that third argument is for primitives, which can be mounted where no
translations were rendered at all), and asserts both locales. It found exactly
two keys, which is also the evidence that the rest of the product was clean.

Publish **leaf paths, not the group**, when a group mixes audiences.
`identity.auth` holds every way sign-in can fail — the throttle wording, the
deliberately vague refusal — and the browser needs two sentences out of it.

The auth screens are one layout. `AuthLayout` takes `wide` for the registration
form and nothing else varies; `ConfirmPassword` used to draw its own brand mark
and its own raised card, which made it the only screen in the product with a
shadow on something that is not floating, plus a `rounded-[6px]` and a
`font-bold` the design system bans. The warning it carries is an `AppAlert`
now.

**The two-factor challenge cannot be driven in a browser**: it exists only
between a verified password and a granted session, so reaching it means typing
somebody's password. `TwoFactorTest` renders it instead and asserts the
component and its props. A screen no browser can reach still needs the test
that proves the document builds.

**The session registry drifted from the live session, and the Security screen
is where it showed.** `SessionRegistry::touch()` updated a row and returned
silently when there was none, and only `AuthenticateUser::complete()` ever
created one — which a remember-me cookie never calls. So a session Laravel
rebuilt from that cookie was invisible: the operator's own device was missing
from their list of devices, while the row for the session it replaced sat
there looking live with a Sign out button beside it. `track()` registers the
unknown session instead, for the same indexed read it was already doing. Two
existing tests asserted "no sessions left" after revoking the others and after
a password change; both now assert that exactly one is left and that it is the
one the request was made on, which is what the word *others* meant all along.

`AppCopy` hides its button with no secure context, which is every installation
served over plain HTTP — the two-factor setup key looks like plain text on a
dev box and gains its button in production. That is the documented behaviour,
not a broken render; check `window.isSecureContext` before chasing it.

A QR code is the one white surface in the product. A dark card behind a dark
code is a code no camera reads, so `bg-white` there is a scannable surface
rather than a design one, and the comment above it says so.

**The client area is driven in a browser through impersonation.** A staff
session and a customer session cannot coexist usefully — `CurrentActor` checks
staff first — but `Impersonator` replaces one with the other and restores it on
exit, which is how the portal gets looked at without anybody typing a
customer's password. The reason is required and both ends are audited, so the
pass leaves a truthful record of itself. Seed the rows the screens need, drive
them, press Stop, delete the rows.

Four things that pass every test and are wrong on the screen, found in one
sitting on the portal:

- **The portal nav drew a scrollbar under itself on every desktop** and still
  clipped the last destination. `overflow-x-auto` on eleven short labels is a
  link a customer cannot see and does not know to scroll to; it wraps now.
- **Every date-only column in the portal printed `2026-10-25`** beside
  timestamps that were localised. The admin copies of those same screens had
  already been fixed once — the portal renders the same records through
  different pages, so the rule has to be applied per page, not per record.
- **`class="block"` on an `AppStatus` does nothing**, because the component's
  root is `inline-flex` and which of two utilities wins is decided by the order
  Tailwind emitted them in, not by the attribute. Wrap it in a `span` instead.
  This is the same cascade trap as `border-line` beside `border-danger`, and it
  will keep happening: **a utility that fights a primitive's own class is a
  coin toss, so wrap rather than override.**
- **The impersonation banner was hard-coded English** above a portal that was
  otherwise entirely Turkish — and `identity.impersonation.active` had existed
  in both language files the whole time, read by nobody. Published as leaf
  paths, and `FrontEndTranslationsTest` now covers them.

The client dashboard still says nothing about services or domains, because the
controller does not send them. That is a payload change with a test behind it
rather than a redesign, and it is worth doing: a hosting customer opens the
portal to look at what they are running.

**Both paginated portal lists had no pagination.** Invoices and transactions
printed `1 / 3 — 47` as plain text and offered no control at all, so a customer
with more than twenty invoices could not reach the older ones — and the page
number told them exactly how many they were missing. The controllers send
`linkCollection()` now and the screens use `AppPagination`, which hides itself
when there is one page. A list that paginates server-side and renders a count
client-side is not a list that paginates; when converting one, check that
something actually links to page two.

A payment's status reached the invoice screen as a **translated label only**,
so it could not be toned. Same rule as everywhere else: a status crossing to
the browser is two fields, `status` for the tone and `statusLabel` for the
word. It is the third time this has been found — orders, tickets, and now
payments — and each was written by somebody who thought a label was enough.

**Removing a stored card happened on the first click** in the portal. It is a
level-2 confirmation now, and the sentence says what actually goes: the
instrument the next renewal would have been charged to, with nothing already
paid affected. That is the tenth first-click destructive action found in this
pass, and the first one in the customer's own area.

**A section titled the same as its page is a heading that says nothing twice.**
The invoice detail headed its document "Invoices" and the billing form headed
itself "Billing details" under a page called Billing details. Neither needed a
heading at all: the page heading and the status beside it already say what the
screen is.

**Three facts the portal was sent and never drew.** `awaitingUs` on the ticket
list — whose turn it is, which is the one question a customer opens that list
to answer; `service` on the ticket detail — which of their three hosting
accounts the conversation is about; and the department's `description` on the
new-ticket form — the sentence an operator wrote to stop tickets landing in the
wrong queue. All three had been in the payload since the screens were written.
When converting a screen, read the props interface against the template and ask
what is in one and not the other: a fact the server bothered to send is a fact
somebody meant to show.

**A form that cannot be submitted is worse than no form.** With no support
departments configured, the new-ticket screen offered an empty required
dropdown and a Send button, and the server refused on a field whose list was
empty. It says so now instead.

`Client/Orders/Show` passed the invoice's **translated label** to
`statusTone()` and printed the **raw value** as the word — both halves of the
two-fields rule broken on one line, which is why it read "unpaid" in English
and toned as unknown in Turkish. That makes four screens that have made this
mistake; it is always worth grepping for `statusTone(` beside a label when
converting a page.

Orders was the third portal list paginating server-side with no control to turn
the page.

**The client area is converted, all twenty screens.** Two of them —
`Client/Contacts` and `Client/Profile` — were still entirely hard-coded
English, which is how a portal ends up half Turkish; `portal.contacts` and
`portal.profile` hold their vocabulary now. Profile was also asking for a tax
id under the label "Tax id" while the billing screen two clicks away asked for
the same field under the seller's own word. Two names for one field in one
portal is how a customer starts doubting both; it uses `useTaxIdentity()` like
everything else.

**Three more first-click destructive actions, all in the customer's own area:**
removing a contact (a person's access, from a bare red word), revoking an API
token (which stops a running integration), and deleting a webhook endpoint.
Thirteen found in this pass now. The pattern is always the same — a
`router.delete` wired straight to a click — so it is worth grepping for
`@click="remove` and `@click="revoke` on any screen before calling it
converted.

**A checkbox that cannot change anything is disabled.** The notification
preferences drew four switches, and the two the platform always sends moved,
saved and did nothing, with a sentence underneath explaining that they did
nothing. A control that lies is worse than a control that is absent.

**A secret shown once should be copyable.** The API token and the webhook
signing secret both appeared in a `<code>` block to be selected by hand — the
one moment either value is readable at all, since the table keeps a hash.
`AppCopy` on both.

**Contacts, tokens and webhooks cannot be driven in a browser.** They sit
behind `impersonation.blocked`, which is right — a token is a credential that
outlives the session that made it, and whatever an operator is impersonating a
customer to fix, it is not to walk out with one. So the only way to see those
three rendered is to be the customer, which means their password. They are
covered by feature tests and by the static gates, and that is the honest
ceiling. Confirming the 403 in the browser is itself worth doing: the message
says exactly why.

**Seventeen screens paginated server-side and offered no way to turn the
page** — fourteen in the admin area, three in the portal. Each printed "Page 1
of 3 — 47 invoices" as plain text. Nothing caught it: every one of those
screens rendered, every prop was asserted, and a paginator nobody links to
still paginates correctly. The rule is checked now at the two places it can be
stated — `tests/Feature/PaginationTest.php` asserts that a controller which
paginates sends `linkCollection()`, and that a screen given a `lastPage` draws
an `AppPagination`. The Resource Graph's two screens are exempt **by name**,
because they roll their own previous/next pair; an exemption that matches a
shape is an exemption everything eventually matches.

`AppPagination` itself had no test although it was about to be wired into
twelve screens at once. It has three now: it links to the other pages, it
renders Laravel's `&laquo; Previous` as a word rather than an entity, and it
draws nothing at all when there is one page.

**Completing a cancellation request terminated a service on the first click**,
from a solid primary button in a queue. It is the most destructive thing this
product does, and the queue offered it with no confirmation, no reason and no
sentence saying which of the two kinds of request this was. The dialog is level
4 for an immediate request — reason plus the service's own name typed out — and
level 2 for an end-of-term one, which only stops the renewal. The reason is
**sent**: `CompleteCancellation::complete()` takes a note and puts it on the
service transition, so it lands on the audit row beside the customer's own
words. A reason a screen collects and an endpoint discards is a sentence nobody
reads.

A page heading that differs from its menu item by one word leaves the
breadcrumb saying the same screen twice — "Clients › Products/Services ›
Products and services". The trail drops its last crumb when the two match
exactly, so a screen whose name *is* the menu item's takes the label from
`ui.nav.*` rather than restating it. Where the two are genuinely different
levels (Utilities › Module Queue › Operations) all three crumbs are right.

**A duplicate key in a language file is silent, and the later one wins.** Today's
batch inserts put new keys at the top of a group that already had them further
down, so a screen went on printing the old wording while the file showed the new
one twenty lines above it — the product-group form's submit button said "New
group" no matter what was written for it. `tests/Feature/LanguageFileTest.php`
now refuses a key stated twice in one file, and separately refuses a key that
exists in one language and not the other. It found fourteen of the first on the
day it was written.

Its parser follows an anonymous `[` as well as a named one, because a list of
steps — each with a `title` and a `body` — otherwise looks exactly like the same
key stated four times.

That comparison is **lowercased**, not collated. "Review Queue" in the map over
"Review queue" as a heading is one name, and `localeCompare` with a collator
answers a runtime built without the full ICU data with a plain byte comparison —
so the duplicate comes back on somebody else's PHP build with nothing saying
why. `AdminLayout.test.ts` mounts the shell with a heading that differs only in
case.

**A page prop must not be named like a shared one**, and now a test says so.
`tests/Feature/SharedPropsTest.php` reads the shared names out of
`HandleInertiaRequests::share()` and refuses any first-level key of an
`Inertia::render([…])` payload that matches one. The settings screen taught the
rule and Connect repeated it — both sent something called `brand`, both lost the
company name from the footer chrome while the page itself looked fine. The
sweep found three more: the Operations screen shadowed the topbar's own
operation counts, the templates screen shadowed the request locale with the
locale being edited, and the register form was sending its own copy of
`taxIdentity` instead of reading the shared one through `useTaxIdentity()`.
The payload is sliced out by `Inertia::render('…', [` and its own closing line,
because a validation-rule array sits at exactly the same indentation as a
payload key.

**Laravel's own sentences are not in `lang/` until somebody publishes them.**
Every validation error in this product — every form, admin and client — came out
of the framework's built-in English, so a Turkish operator filling in a Turkish
form was told "The event field is required." It was invisible because no test
had ever read a *refusal* in Turkish, and no screen shows one until somebody
makes a mistake. `php artisan lang:publish` plus a Turkish `validation.php`,
`auth.php`, `passwords.php` and `pagination.php` fixes it; `LanguageFileTest`
asserts the parity and that four of those lines actually differ from the
English.

**A date or an amount is handed to `RenderTemplate` as itself**, not as a
string, and worded there in the locale the message is being rendered in. A
caller can only format with the locale of whichever process dispatched the
event, which is how a Turkish invoice email came to say `2026-10-11` among its
Turkish sentences. The preview on the templates screen passes real dates for
the same reason: a preview that words them differently from the message is a
preview worth nothing.

**"Leave empty to publish now" has to be true.** An announcement saved with no
publish date stored null, and `Announcement::scopeVisible()` wants a date that
has passed — so the operator saw their announcement in the admin list and no
customer ever saw it anywhere. The screen looked like it had worked. After
writing a hint that promises a default, check the write actually applies it.

**Pagination has no exemption any more.** The Resource Graph's two screens kept
a hand-written previous/next pair, which was two untranslated words and no way
to reach page seven; they use `AppPagination` like everything else and
`PaginationTest` no longer names them.

`AppSegmented` is the segmented control — a few mutually exclusive choices shown
at once, for the currency strip on a price matrix and the language strip on the
templates screen. Toggle buttons with `aria-pressed`, not a tablist: there are
no panels, and `role="tablist"` promises a `tabpanel` that does not exist.

`.row-actions` only hides inside `.data-table`. On a list that is not a table it
is inert, and writing it there promises a hover behaviour that never happens.

**Every page in `docs/design/propagation.md` is converted** (2026-09-25, 105 of
105). A screen added after that date gets a row and is ticked the same way: the
gates, then the browser.

**One browser holds both guards.** Separate session *keys* are not separate
sessions: an operator signed into `/admin` who also signs into the portal — to
see what a customer sees, or because the storefront signed them in at checkout —
is ordinary, not impossible. `CurrentActor` resolved staff-first everywhere, so
`CurrentCustomer::contact()` got a staff user on a client route and threw: **every
page of the portal answered 404**, with nothing saying why. It now takes the
guard of the area being asked for — `Guard::fromRouteName()`, falling back to the
path because the organization boundary runs as global middleware before a route
is resolved — and `ClientAreaTest` drives both areas with both sessions open.

The client dashboard had waited for Phases 6 and 7 and was then left behind: it
showed invoices and orders and never mentioned the services or the domains the
customer actually bought. It shows both now, soonest renewal and soonest expiry
first, each gated by its own portal permission. A docblock that says "arrives
with Phase N" is a TODO with better manners — grep for them when Phase N lands.

An audit slug is not a sentence. `Admin/Licence/Index.vue` turned
`licensing.token.refused` into "Token refused" in the page, which reads as a
translation right up until somebody switches to Turkish; the controller sends
the wording now and `VocabularyTest` reads the actions out of the source rather
than a hand-written list.

**The portal is not the console, and now it does not look like one.**
`ClientLayout` is a brand band with a tab bar under it: the first row is *who*
— the seller's brand, the theme, the account — and the second is *where*, six
destinations with the current one underlined in the accent. Everything about
the account itself (profile, security, notifications, contacts, tokens,
webhooks) moved into the account menu, because eleven destinations in one bar
wrapped onto a second line and a customer looking for their invoices read past
"Webhooks" to find them. `ClientLayout.test.ts` pins which list a destination
is in, and that Overview is not marked current on a screen underneath it.

The column is 1024px and the footer carries the brand: a customer reads one
invoice where an operator compares forty rows, and on a white-label
installation the name at the bottom of the page is the one they have a
contract with.

**Every region of a portal screen is a framed panel** (`AppCard`), where the
admin's default is a hairline (`DetailSection`). Both are right: an operator
screen has forty regions and rectangles would flatten it, a customer has four
and each of them is a separate object. `AppCard flush` + `AppTable flush` is
how a table sits inside one — the card keeps the frame, the table gives its
own up, and the result is one rectangle with a header above the column names
rather than the card-in-a-card the design system refuses. `designSystem.test.ts`
asserts both halves, because either one alone still draws two borders.

**Nothing in this product spoke Turkish until today, and everything was
translated.** `locale` on a staff user and on a contact was read by exactly
one thing — the notifier, choosing the wording of an email — while every
rendered page used `config('app.locale')`, for everybody, for ever. Half the
work in `lang/tr` was unreachable and no test could see it, because a test
asserts behaviour and this was a column stored and read by nothing.

`SetLocale` (web group, after the session) answers it: the person's own
locale, then — for a contact — the customer's, then the installation's, and
only a locale this installation ships. `LanguageSwitch` is beside the theme
switch in both shells and writes to `PUT /admin/locale` / `PUT /locale`
(`routes/auth.php`, registered once per guard, blocked during impersonation
because the row it writes is the customer's).

**A language change is a full document reload, not an Inertia visit.**
`useTranslations()` reads a JSON block rendered into the document and Inertia
replaces only the page component, so switching without reloading gave a
Turkish heading over an English table — the exact half-translated screen all
of this exists to prevent.

`App\Support\Locales` is the one list of what this installation speaks; the
client form and the template editor each had a private copy of it, and the
switch would have been the third. The list the control is drawn from and the
list the write is validated against must be the same list, or a language
appears in a control and is then refused.

Two identical-looking controls on one screen are two controls nobody can tell
apart: the template editor's EN|TR picks the language being *edited* and the
topbar's picks the language being *read*, so the first one is labelled on the
screen and not only to a screen reader.

The shell had its own untranslated words — the skip link, four `aria-label`s,
the account menu, the help links, "Select every :noun on this page". A
primitive takes the three-argument `t(key, {}, 'English')` form, because it
can be mounted where no translations block was rendered at all.

**The shop belongs to the seller, not to whoever is looking at it.**
`ResolveStorefrontOrganization` only set a boundary when there was none, so a
signed-in customer browsed the storefront under *their own* organization: a
customer organization sells nothing, so the shop was empty and branded with
the customer's own name — "Customer is installed and running" on the page of
the company they buy from. A client session now narrows to `ResolveSeller`'s
answer; staff still keep their own, because a reseller's operator previewing
their storefront is what that exception is for.

**A cart line is reached through its cart, not through the router.** Implicit
binding happens inside `SubstituteBindings`, which runs before a route
middleware — so the line was looked up under the signed-in customer's
boundary while the cart belongs to the shop, and Remove answered 404 on their
own basket. `prependToPriorityList` does not fix it: the entry lands in the
list and `SortedMiddleware` still leaves the route middleware where it is.
Resolving the line from `ResolveCart::current()` is also the rule the code
already claimed — a line is editable only by the browser holding the cart's
token.

**"Then :amount" is a sentence about the next invoice, and the next invoice is
taxed.** The checkout quoted the gross for what was due now and the net for
what renews, on the same screen, for the same lines: "Total due now 179.88,
then 149.90". `CartTotals::$recurringWithTax` is what a customer is quoted;
`recurringTotal` stays the net that an order row stores and a renewal
re-prices from. The renewal's tax is worked out on the recurring amounts
alone — a setup fee is charged once, and taxing it into the monthly figure
overstates every month after the first.

**The storefront is a shop, not a console.** `frontend-architecture` says so
in as many words: the Blade themes are out of the design system's scope, so a
hero at `text-5xl` there is not a violation. What *is* wrong on a public page
is operator vocabulary — the admin price matrix's "One row per billing cycle,
one column per currency." was printed under Add to cart, and a product's
"Collects a domain at checkout" is a sentence about a setting rather than to
a customer.

**The storefront header links only what has something behind it.** Domain
search and the knowledge base existed and nothing pointed at them — a shop
that sells domains with no way to reach the search. `StorefrontComposer`
answers which public sections are populated (memoised with `once()`, because
the layout and the page are both composed), and the layout draws a link per
section the way the admin rail does: no TLDs on sale, no Domains link, rather
than a link to "no extensions are on sale yet".

What a visitor still cannot do on the shop is choose a language: `SetLocale`
follows the signed-in person, and an anonymous visitor gets the
installation's. A public language switch is a cookie decision nobody has made
yet.

**Stripe, cPanel and Namecheap are packages now** (`modules/infracms/`), and
core registers only the manual three. The rule is the one the plan states: an
adapter this repository has never proven against the thing it adapts should
not be in the distribution every installation runs — the day Stripe changes a
field, a core release is the wrong unit of shipping.

**It is a migration, not a deletion.** An installation whose settings already
configured one of them gets the package installed, configured from those same
values and enabled, by
`2026_10_09_000100_move_core_adapters_into_modules`. A release that silently
stopped taking payments would be the worst upgrade this product could ship.
The migration reads `config()`, not `env()` — PHPStan says why, and the reason
matters: `env()` outside the config directory answers null wherever the config
is cached, which is every production deployment. So the three old config
blocks stay in `config/platform.php`, marked as the upgrade source and read by
nothing else.

`loadModuleClasses($slug)` in `tests/Pest.php` puts one package's classes on
the autoloader without installing or enabling it — a test of an adapter has no
reason to enable a module, and a module's `src` is not on Composer's map
(`ModuleLoader` registers its own, prepended, when a module is enabled).

Two lessons from doing it: a `/*` block comment left unterminated eats the code
after it (that is the second time — the first was `FrontEndTranslations`), and a
`php artisan migrate` interrupted mid-run leaves the test database with tables
and no `migrations` row, which then fails as "table already exists" on every
later run. `db:wipe --env=testing` then `migrate --env=testing` is the way back,
with nothing else running against that database.

**Phase B has begun** (`advanced-operations-plan.md`). Three things of it are
in: the credential vault, the first real monitoring adapter, and the health
sweep Phase A deferred.

`monitoring-prometheus` is the first adapter family that reads a real system.
It asks four instant queries across every target rather than one query per
host — a fleet of four hundred machines is four requests — and every target is
`preg_quote`d into the matcher, because a node key is a hostname full of dots
and an unquoted `db1.example.com` also matches `db1xexample.com`, which is a
reading attached to the wrong machine that nothing would ever report. Its
address is module configuration and its token is not: the token lives in the
vault under `monitoring/token/prometheus`, read at the moment of the call so
a rotation takes effect without restarting a queue.

**An adapter's credential is written from the Adapters screen**, write-only,
behind the password challenge, and the screen says only whether one is stored
and when it last changed. `AdapterCredentialTest` asserts the value reaches
neither the props nor the rendered page — the licence key's rule, for the same
reason.

**The health sweep asks a question about rows, not about the clock**: which
adapters have not been checked recently enough, at the pace each adapter's own
`RateLimits` declares. A check that found the same state is a skip rather than
a change, because a sweep reporting twenty changes every five minutes makes
the one adapter that actually changed impossible to see.

Two testing notes from building it. **`Http::fake()` called twice adds a stub
rather than replacing the first**, so a test that "changes the answer" changes
nothing — hold the state in a static and register one stub. And **enabling a
module loads its classes into the process for the rest of the run**, which
broke `ExampleFileProbeTest`'s assertion that reading a manifest is not
consent; a test that needs an enabled adapter should use a package no other
test asserts is unloaded.

**The daily point is the one series this platform keeps.** Telemetry keeps
the present and never the history (§14) — Prometheus and Zabbix own that — and
`resource_metric_days` is the named exception, because a capacity answer
cannot be built from a single present value. One row per node, metric and day
is 365 rows a year for a thing, which is bounded by arithmetic rather than by
hope.

It is **accumulated by the collector as readings arrive**, not rolled up at
midnight: a nightly job reading `resource_metrics` would find one value, the
last one written, and call it a day's average. `sum` and `samples` are stored
rather than an average, because a running average that has lost its
denominator is a number that drifts. The day comes from the reading's own
timestamp, so a batch arriving at 00:00:02 with a 23:59 sample belongs to
yesterday.

`CapacityForecast` answers the only capacity question an operator asks —
when does this run out — with a straight line through the daily averages and
no smoothing, because a model an operator cannot check in their head is a
model they will believe when it is wrong. **It declines more often than it
answers**: fewer than seven days is a week rather than a trend, a flat or
falling line is "not the disk to worry about", and a metric whose ceiling
nothing reported gets no answer at all. A ratio fills at 1.0 and bytes used
fill at bytes total — the total being a reading the adapter sent, never a
number this platform chose.

**What an adapter calls a machine is not what this platform calls it.** A
server's node key is its ULID and Prometheus reports
`instance="web-1.dc2:9100"`, so without a mapping every reading from a real
monitoring system lands in `unplaced` while the installation is configured
perfectly — and the Telemetry screen stays empty for a reason nobody can see.
`RecordSamples::byHostname()` matches the leftover targets against the
`hostname` on the node's attributes, lower-cased, with the port stripped
(`[2001:db8::1]:9100` is a host and a port written the way IPv6 has to be
written; splitting on the last colon without the brackets leaves half an
address). **Ambiguity is refused, not resolved**: two nodes claiming one
hostname match nothing at all, because a reading attached to the wrong machine
is worse than a reading nobody placed — the first one is acted on.

**Scored placement is in** (`PlacementStrategy::Scored`, `PlacementFactor`,
`ScorePlacement`, `service_placements`), and it is a weighted mean over the
readings the graph happens to hold. Ten of §4's thirteen inputs; reserved
capacity and compatibility are left out because no column states them, and a
factor scored from a number nobody entered always says the same thing. Three
rules hold it up:

- **A discovered fact never refuses a placement.** Graph health pulls a node
  down hard and cannot remove it from the running: a monitoring adapter that
  breaks at three in the morning would otherwise empty the candidate set and
  fail every provisioning job on the installation. Only an operator's own row
  — `maintenance`, `full` — refuses, before anything is scored.
- **What nothing reported is assumed to be the average of the candidates that
  did**, marked `assumed` on the row. Both obvious alternatives send every
  service to the one machine nobody can see: scoring an unreported factor as
  zero makes it the worst node, and taking the mean over only a node's own
  factors judges it on how empty it is, which on an unmonitored box is the best
  score on the list. That second one is what the first test written here
  actually caught.
- **The record is numbers and slugs, never a sentence** — the `health_message`
  rule again. It is written for *every* strategy, not only the scored one,
  because "the group is set to fewest accounts, and this is what the disk was
  doing at the time" is the sentence somebody wants six weeks later. Writing it
  never throws into the placement: an audit trail that could not be written must
  not become an outage.

A new `PlacementStrategy` member is a new option on the server-group form and a
new validation case for free — both are built from `cases()` — but
`VocabularyTest` fails until both languages name it, which is the guard working.

**`phpunit.xml` sets `memory_limit` to 1G**, and the arch tests are why: they
parse every file under `app/` in one process and the default 128M ran out as
the codebase grew. The symptom is not a failing assertion — it is a fatal error
inside php-parser on whichever file happened to be next, which kills the worker
at the *first* test and makes the whole suite look like it failed. `composer
stan` already carries `--memory-limit=1G` for the same reason; running
`./vendor/bin/phpstan` bare now crashes.

**Phase C has begun, and addressing is core.** `advanced-operations-plan.md`'s
table put IPAM in an `ipam` module and that cannot be built: **a module cannot
draw a screen.** `ExtensionPoint` has `Navigation` and `Widget`, and a
`NavigationItem` carries a *path* — but nothing registers routes or view paths
for a module, so the path has to be one core already serves. The worked example
is the evidence: `modules/example/status-board` points its nav row at
`/admin/health`. A module ships adapters, calculators, channels and permissions;
pages are core's. §5 is mostly pages, so IPAM is core and `phase-c-plan.md` §2
records the correction. The vendor device adapters stay modules, exactly like the
monitoring ones.

**An assignment is not a graph edge**, although the graph is already append-only
with a closing timestamp and "who had this in March" is already a `where` clause
there. An edge belongs to its container's organization and the graph refuses one
whose ends are in different subtrees (ADR 0043) — an address belongs to the
seller's range and the service holding it belongs to the customer, so "service
contains address" is exactly the refused edge, and inverting it would let a
customer walk up to the seller's prefix. `ip_assignments` is its own append-only
table, owned by the seller. The graph still gets prefixes and devices when
topology lands, because those are all on the provider's side.

**Every address is sixteen bytes and the text is derived from them.** IPv4 is
mapped into the IPv6 space, so one column and one index sort and compare both
families and "is this inside that prefix" is a range query rather than a loop.
`2001:db8::1` and `2001:0db8:0000:0000:0000:0000:0000:0001` are one address
written twice; storing what was typed would hold two rows for it, assign it
twice, and be unable to say who had it. `IpAddress::parse()` and `inet_ntop`
decide what an address is called — an operator's spelling decides nothing.

**A free address has no row.** A /64 holds eighteen quintillion of them, so a
row appears when an address is assigned, reserved, quarantined or given a
reverse-DNS name, and *free* is the prefix's range minus the rows. It also means
a capacity figure is sometimes honestly unavailable: a /64's is larger than a PHP
integer, so `addressCount()` answers null above 2^62 and the screen prints "too
large to count" rather than a bar reading 0.0000000001%.

**Releasing an address quarantines it by default.** One handed to a new customer
the morning after a spammer left it arrives on blocklists the new holder never
earned, and their mail fails for a month for reasons nothing in this platform can
explain. An operator can put it straight back deliberately.

**A prefix's parent is computed, not typed.** The nearest containing network,
worked out on write, and a new shorter prefix adopts the ones that were hanging
off its own parent — a parent somebody chose by hand goes stale the moment a /16
is added above a /24. Deleting a supernet re-parents its subnets rather than
taking them with it.

**`192.0.2.5/24` is refused rather than masked**, and the refusal names
`192.0.2.0/24`. It is somebody's address typed where a network was wanted;
accepting it quietly files the address under a network nobody named, and the
mistake surfaces months later as an address that is missing. The form request
validates by *parsing* rather than by a regex, so the value object's sentence is
what the operator reads — a second answer to "what is an address" in a regex is a
second answer that disagrees.

**Concurrency again, at the guard.** `AllocateAddress` locks the prefix row
rather than the address rows, because the address being allocated has no row yet
— there is nothing else two callers could both hold. The test does not race
threads: it asserts the unique index on `(ip_prefix_id, address_bytes)` refuses
the second row whatever the lock did.

**The product wears `DESIGN.md` now** (`Apple-design-analysis`), across all
113 screens: the console, the portal and the storefront. It was done at the
**token layer**, which is what ADR 0040 bought — re-valuing
`resources/css/app.css` moved every screen at once, and the only components
that needed touching were the ones whose *shape* changed.

**Every value is DESIGN.md's or a derivation with the derivation written
beside it.** Action Blue `#0066cc`, ink `#1d1d1f`, canvas white, parchment
`#f5f5f7`, hairline `#e0e0e0`, the dark tiles `#1d1d1f/#252527/#272729` on
black, radii 5/8/11/18/pill, the 4-8-12-17-24-32-48-80 ladder. Where that
document is silent — status colours, a selected row, a hover fill — the value
comes from Apple's own system palette or from a colour DESIGN.md does state,
and says so in a comment. Nothing in the file is a colour somebody liked.

**It has two registers and both are used.** The marketing members (56 / 40 /
28) build the storefront, where a tile occupies roughly one viewport; the
utility members (`caption` 14, `fine-print` 12, `micro-legal` 10,
`button-dark-utility`) build the console, where a screen carries forty
controls. Applying the marketing end to a table would have been reading half
the document.

**`--surface-chrome` is black in both appearances** (`global-nav`), and
`.on-chrome` re-points the tokens for its subtree so the rail, the topbar and
the portal header need no on-dark variant of anything.

**A custom property containing `var()` is substituted where it is declared,
not where it is used.** `@theme` sets `--color-content: var(--text-primary)`
on `:root`, so a descendant redefining `--text-primary` changes nothing a
Tailwind utility reads — `.on-chrome` had to redefine `--color-*` as well.
Until it did, the breadcrumb sat at **1.24:1** on the black bar and looked
fine in a screenshot, because #1d1d1f on #000 is invisible rather than wrong.

**There is exactly one shadow and it is for photography.** `--shadow-product`
on storefront imagery; `--shadow-raised` and `--shadow-panel` are `none`.
Elevation is the surface changing colour, and a floating layer is `.floating`
— parchment at 80% behind `blur(20px)`, solid under
`prefers-reduced-transparency`, because that preference is somebody telling
the OS that translucency makes text hard to read.

**`tools/design-review.mjs` is the browser pass, mechanised.** It signs in,
walks every parameterless page in both appearances, captures each one, checks
for horizontal overflow at 1440/1280/1366/1920 and runs axe over it. It does
not decide whether a screen *looks* right — "the heading says the same thing
twice" is not machine-checkable and never will be — but it found, in one run,
what nobody had looked for:

- **86 renders failing colour contrast.** Two causes: the breadcrumb above,
  and `ink-muted-48` (`#7a7a7a`), which is 4.6:1 on canvas and **3.94:1 on
  parchment** — and the page is parchment.
- **A textarea with no label on every ticket form.** `AppRichText` drew a
  `<label>` beside the control rather than *for* it, so every screen reader
  announced an unlabelled edit box. It had been there since the component was
  written.
- **`/admin/network/addressing` answering 500** — the development database had
  never been migrated for the IPAM tables. `migrate --env=testing` is not
  `migrate`, and that is the second time (the `secrets` table was the first).

Run it with `DESIGN_EMAIL=… DESIGN_PASS=… node tools/design-review.mjs`. It
needs a staff account; create a throwaway one, and delete it afterwards.

**Three surfaces, three references** (2026-09-26). The shop is `apple.png`, the
console is `whmcs-admin-dashboard.png`, the portal is `whmcs-clientarea.png` —
and that is coherent rather than inconsistent: the public shop is a frame
around a photograph and the operator tools are what the people running this
have spent years in.

**The shop's bar is the page colour, not black.** apple.com's `global-nav` is
`#f5f5f7` with dark links in light appearance and black in dark, which is what
`--background` already resolves to. A black bar over a white page is the single
thing that made the storefront read as a dark SaaS product rather than a shop.
The hero tile *is* black, the secondary action is an **outlined pill** rather
than a text link, and the two sit side by side at equal weight.

**The console's bar is navy** (`.on-chrome`), because its reference and the
shop's disagree, correctly. It is also where the contrast trap moved: navy is
lighter than black, so the muted text on it has to be lighter than the dark
appearance's own — 10px of `#98989d` on `#22364a` is 4.31:1, which the command
palette's keycap failed at.

**`StatBlocks` is the dashboard's four colour tiles**, and deliberately not
`MetricStrip`, which stays the restrained answer everywhere else. A dashboard
is the one page read from across a room. **The colour is positional, not
semantic**: nothing about "50 tickets waiting" is a warning until somebody
decides it is, and toning it amber would be the platform inventing an opinion —
so the tones cycle by position and the status vocabulary keeps its meaning.

**A destination with children keeps its row and gains a chevron.** Flattening
the filters into the dropdown buried the six ordinary destinations under
seventeen filters; they open beside the menu now, which is the shape every
panel this product replaces uses. The row itself is still a link to the whole
list.

**The shop has image slots and this installation has no images.**
`StorefrontImagery` looks for `public/storefront/hero.{webp,png,avif,jpg}` and
`public/storefront/products/<slug>.…`, and answers null when they are absent —
no placeholder frame, no grey rectangle, no gradient standing in for a
photograph. A tile composed for an image reads perfectly well as a typographic
tile without one; a picture of a missing picture does not. The convention is a
path rather than a column on purpose: an operator drops a file in and the shop
picks it up, with no migration and no upload screen. `img-src` is `'self'
data: blob:`, so an external image host is refused by the installation's own
CSP — the files have to be local.

**The portal wears `whmcs-clientarea.png`.** Four bands and a column: a dark
utility strip saying who is signed in, a white header carrying the brand, the
tab bar, and a trail band — then the account beside the page.

**The trail names the section, never the page's own sentence.** The overview
is headed "Hello, Ayşe." and a breadcrumb repeating that reads as a bug rather
than as a greeting: the `h1` is where a sentence belongs and the trail is where
a noun does. It is also where a contrast trap lives — the band is
`divider-soft`, which is darker than the page, and `--text-muted` on it is
4.45:1 at 12px.

**A section with several screens is a dropdown in the bar**, which is what
`AppMenu`'s `tab` variant exists for: a trigger the height and weight of the
tabs beside it, rather than a button of a different shape sitting in a row of
them.

**`AppCard` takes a `tone`** — a coloured rule along the top and the glyph
beside the title — and the portal uses it where the admin keeps its hairline.
That is not inconsistency: a customer's overview is eight panels of unrelated
things and the colour is how they tell "you owe money" from "here is some
news" before reading either; an operator's screen has forty regions, and forty
colours would be none.

**`PortalStats` is the customer's count strip and `StatBlocks` is the
operator's.** Same idea, different register: solid saturated tiles for a
dashboard read from across a room, a white panel with one coloured rule for
somebody reading their own three services on a laptop. The portal's figures
come from the rows already on the page, so a count cannot disagree with the
list under it.

**`tools/design-review.mjs` can sign into the portal** (`DESIGN_PORTAL=1` with
`DESIGN_CLIENT_EMAIL`/`DESIGN_CLIENT_PASS`). Impersonation is how a *person*
reaches the client area and a poor thing for a script to drive: the control is
a row action on a contact, revealed on hover, inside a menu, behind a
confirmation — four places for a selector to break. A throwaway contact created
for the run and deleted after it is deterministic and involves no real
customer's password.

**The shop's artwork is a build output, not a file somebody exported once.**
`tools/storefront-render.py` builds and renders it in Blender — an anodised
slab, studio-lit, on transparency — and `public/storefront/` is where
`StorefrontImagery` looks. The day the brand changes, that script is edited and
re-run; a PNG in `public/` with no source is a PNG nobody dares touch. Its
`PRODUCTS` map is keyed by slug because `StorefrontImagery::product()` looks a
file up by slug and has **no fallback**, so a `default.png` would be a render
nothing asks for — the same mistake as a setting nothing reads.

**A `box-shadow` behind a transparent product PNG is the element's rectangle.**
`.product-shadow` drew a soft grey oblong behind a product that has no corners,
on a tile dark enough to show it. `filter: drop-shadow(…)` follows the alpha,
which is the shape somebody rendered. The token holds the whole `drop-shadow()`
function now, not a shadow triple.

**A picture's size comes from the file** (`App\Support\View\Picture`,
`getimagesize()`), never from two numbers written into a template. The document
reserves a box from `width`/`height`, and a hard-coded `2000x1200` beside a
2000x900 render reserves a box a third taller than the artwork — `object-contain`
then centres the product in it and the empty band under it is in no stylesheet,
because it is not in one. A file `getimagesize()` cannot read answers false
rather than throwing, and that reads as **no picture**: the tiles are composed
to work without one, and a broken `<img>` on a public shop is worse than a tile
that never mentioned a picture.

**`w-full` of a 680px column is 680px.** The hero image carried a
`max-w-[52rem]` it could never reach, because it sat inside the column the
sentences need. Copy and product do not want the same width — the copy column is
nested inside a wider one now. A `max-w-*` that a parent already caps is a class
that looks like a decision and is not one.

**Framing is arithmetic, not taste.** The catalog card crops to a **square** so
a row of plans is a row of equal tiles, and a slab photographed at the hero's
three-quarter angle is a 2.3:1 shape — centred in a square it leaves the card
half empty above and below. The product shot is near-overhead and turned on the
spot, which is roughly 1.1:1. The hero keeps the three-quarter angle and the
*frame* is cropped to it (2000x900) instead of the composition being floated in
one.

**`bpy.ops` needs a context an operator would be run from, and silently does
nothing without one.** Driven from outside Blender's UI, `select_all` +
`delete` cleared nothing, so three renders were composed against the leftovers
of the ones before them — one unit kept coming back wearing a material from two
versions ago. `bpy.data.objects.remove` does not care about context. The same
trap as the `static fn` in a `->map()`: it does not fail, it just does not
happen.

**The dashboard is the one framed screen in the console.** Every other admin
page groups with a heading and a hairline; the dashboard's regions are
`AppCard`, because it is genuinely a board of unrelated widgets — revenue,
fleet, the audit trail — and the frame is how an operator tells where one
subject ends and the next begins. `whmcs-admin-dashboard.png` is a grid of
exactly these. Attention stays a hairline: it is one line when nothing is
wrong, and a rectangle around one line is noise.

**The breadcrumb drops the section's name too, not only the row's.** The
Clients group holds a row called "View/Search Clients" and the screen is
headed "Clients", so the trail read `Clients > View/Search Clients > Clients` —
one word twice with something else wedged between the two. `/admin/apps` had
it as well (`Setup > Apps & Integrations > Setup`). Same `differs()` the row
already used, applied to the group.

**A count needs `trans_choice`, not `__()`.** The Setup page told an
installation with one product "1 products", one group "1 groups" and one tax
rule "1 rules", because a unit was stored as the plural and printed beside a
number. The units that are adjectives (`enabled`, `configured`) and the
uncountable ones (`staff`) need no singular, and neither does any Turkish one —
a counted noun there does not take a plural — so the same call is right for all
of them: a string with no `|` comes back unchanged. `catalog.products_count`
was the mirror image, a choice string **nothing called `trans_choice` on**, in
both language files, read by nobody; deleted.

**`health.measurements` was in both language files from the day the screen was
written and read by nothing.** The page printed the array key, so an operator
was told `latency_ms 1`, `unreachable 0` and `licence unlicensed`.
`HealthController::worded()` translates them now and **keeps the key when
there is no wording**, which is right rather than lazy: `QueueCheck` is keyed
by the queue's own name, whoever configured this installation chose it, and a
module's check is not core's to name — `health.measurements.whatever` would be
less use to the operator reading it than the key.

**`LicenceCheck` was the one check that spoke hard-coded English** — three
sentences in the source where every other check reads `lang/`, plus
`'unlicensed'` as a value and a raw enum value for the status.
`VocabularyTest` never caught it because it asked each check for its *name*,
and the name was fine. It now also pins every static measurement key in both
locales and greps that file for a sentence.

**A chart with nothing in it said so twice.** `AppBarChart` drew its legend
("0 tickets") above the sentence "Nothing in this period.", which reads as a
figure that failed to load rather than as a quiet month. The legend is hidden
when the series are empty. The component had no test at all although every
dashboard and report draws one; it has three now, including that the table
underneath keeps the zero rows — a series that drops its zeroes is a series
whose shape is a lie.

**A cached label is only as fresh as the run that wrote it.** The Telemetry
screen listed four organizations called "Customer" until `platform:run
resources` was run again: the projector had been fixed, the rows had not. The
code was right and the data was old, which is the cost ADR 0043 accepted when
it chose to cache the label — worth knowing before debugging the projector a
second time.

**`tools/design-review.mjs` over all 68 admin pages is 128 renders, and it
found none of the above.** Zero axe violations, zero overflow, zero failures —
and the screens were still telling an operator `latency_ms`, "1 products" and
"Clients > View/Search Clients > Clients". The mechanical half is a floor, not
a pass: it proves nothing is broken, and what a human reads is still a human's
job.

**The network device contracts are in, and the write side deliberately is
not.** `NetworkDeviceProvider`, `FirewallProvider`, `SwitchProvider` and
`RoutingProvider` (SDK 1.4, additive — a contract that did not exist cannot
have been implemented), with eleven value objects under
`app/Domain/Infrastructure/Network`. `Capability::FirewallPolicyWrite` exists
and **no interface declares a method for it**: FortiOS would take that write
over the same API, and an interface that let something call `apply()` before
§6's guarded workflow existed would be the shortcut around the workflow, built
first.

**`modules/infracms/network-fortigate` implements all four contracts from one
object**, which is the case `AdapterArea` was split for — one chassis is a
firewall, a switch and a router. A box that is only a firewall still answers
the switch and routing reads, with empty lists, which is the truth rather than
an error.

Four FortiOS particulars that only a careful read of the API turns up, each
pinned by a test:

- `status` is `enable`/`disable` where JSON has a boolean, and a rule somebody
  switched off is a decision that must stay visible rather than be filtered out.
- A **deny is a reject** depending on a second flag (`send-deny-packet`). They
  are different on the wire and different to diagnose — one hangs and one is
  refused at once — so the flag decides, not the word.
- Every address, service and interface on a policy is a list of `{"name": …}`
  naming an object defined elsewhere. Resolving those would be this platform
  reimplementing a vendor's object model and showing a policy that does not
  match the one on the box, so what is shown is what the device said.
- The configuration backup is the **one endpoint answering text, not JSON**.
  Reading it as JSON answers null, stores an empty configuration, and the diff
  step then reads it as "everything has been deleted".

**A port that is administratively up with no cable in it is down.** `status`
is the administrative state and `link` is the physical one; `PortState` keeps
`Down` and `Disabled` apart for the same reason, because a rack of failures
and a night somebody shut eight unused ports must not look alike.

**`DeviceConfiguration::fingerprint()` normalises line endings before
hashing**, and the fingerprint is computed here rather than asked of the
adapter. Two adapters that hashed their own configuration could disagree about
what "unchanged" means, and the guarded workflow's whole safety is that the
diff was built against what the box says *now*.

**A description nobody renders is dead wording.** `CapabilityNames` has
carried descriptions since the Adapters screen was written, and only the
allow-writes dialog drew them — so a read capability's sentence existed and was
read by nothing. The card draws them now, which also earns its place: an
operator on that screen is deciding what this installation may know about their
network, and "how near its limit, never the session table itself" is the
answer.

**A quoted heredoc in the Bash tool still eats backslashes.** `<<'JSON'` wrote
`InfraCMS\NetworkFortigate` from `InfraCMS\NetworkFortigate`, which is invalid
JSON, and the module simply did not appear on disk — the same class of failure
as the doubled backslash `OfficialModulesTest` caught once before, through the
other door. Anything containing a backslash or an escaped quote goes through
the Write or Edit tool, not a heredoc.

**Topology discovery is in** (`DiscoverTopology`, `AutomationTask::Topology`,
hourly). It asks each network-device adapter to describe itself and writes a
`network_device` node, a `device_port` under it, an `ip_address` under each
port and a `vlan` where the same box also answers as a switch — so the
containment walk reaches Router → Port → Address.

**An adapter is one device.** A network-device module is configured with one
address and speaks to one box, so `describe()` is handed the adapter's own key
rather than a list of node keys the way `CollectTelemetry` is. There is no
inventory of devices to draw the list from, and inventing one would be core
guessing at hardware it has never seen.

**The serial is the identity, and the fallback is the adapter key — never the
hostname.** A hostname is changed by whoever last configured the box, and a
node key that moved would leave the old node behind as a device that had
apparently vanished.

**A discovery run retires only what it wrote**, scoped by `source`. Retiring by
kind would take a second adapter's ports with it every time this one ran. That
is `source`'s whole reason for being on a node, and it is the same rule
`ProjectCoreResources` states about never believing it owns rows it has never
seen.

**An `ip_address` node is not an `ip_addresses` row**, and merging them would
destroy the only thing discovery is for. The table is the seller's plan — what
somebody intends to hand out — and the node is what a box says it is actually
wearing; the value is being able to say they disagree. The same applies to
`VlanDescriptor` against the `vlans` table.

**A port's VLAN is an attribute, not an edge.** A VLAN does not physically
contain a port, and `Relation` is a closed list on purpose — inventing a
semantic to carry a number is how a traversal starts answering questions
nobody asked.

**`DunningAndHealthTest` counts the automation tasks, and that count is a
guard rather than a chore.** It has now caught four phases in a row adding a
task to `AutomationTask` and not to the screen — the screen has to offer every
task the command can run, and a new one joins both or neither.

**The guarded configuration workflow is in** (`network_changes`,
`/admin/network/changes`). A change is a record before it is an action: who
asked, why, which ticket, the exact diff, who agreed, what the device said.
Four permissions — `network.devices.view`, `.changes.request`, `.approve`,
`.apply` — and the last carries the password challenge, with the permission
**above** `auth.recent` on the route (Phase 17's rule, now learned four
times).

**A change one person both asked for and agreed to is a change nobody agreed
to**, and that cannot be a permission: a permission says who may approve and
cannot say *whose* change. `DecideNetworkChange` refuses it, the screen does
not offer the button, and a single-operator installation turns approval off
with `platform.network.require_approval` — which is copied onto the row when
the change is requested, like an issued invoice's bill-to party, so relaxing
the setting overnight does not retroactively approve yesterday.

**The apply refuses in §6's order and the order is the feature.** Something
must be permitted to write; back up first, because a backup that failed is a
change that does not happen; the device's fingerprint must still be the one
the diff was read against — a diff approved an hour ago is a diff against a
box somebody else may have edited, and applying it would silently revert
their work; then apply, then **read it back**, because a device that accepted
a configuration and did not keep it is the failure worth catching and no
adapter can report it; a failed verify puts the backup on and the record says
`rolled_back` rather than `failed`.

**Every reason an apply does not go ahead belongs on the change**, so the
refusals are caught inside `ApplyNetworkChange` and written to the row. One
thrown out of it would leave the record saying `authorized` while the
operation beside it said failed — two rows disagreeing about one event. The
state check is the exception, because "you may not apply a rejected change"
is a caller's mistake rather than an outcome.

**`ApplyNetworkChangeJob` has `tries = 1`, the opposite of every other job
here.** Provisioning retries because creating an account twice is harmless
(ADR 0026); a device configuration is not idempotent that way, and a retry
after a timeout might push a configuration that already went on, to a box the
verify step has since rolled back.

**`ConfigurationDiff` is bounded.** LCS is O(n·m), a device configuration is
thousands of lines, and two eight-thousand-line configurations would be
sixty-four million cells. Above two thousand lines a side it answers a
summary — a screen that hung for a minute and then printed six thousand lines
nobody would read is worse than one that says how many lines moved.

**`AppTableRow` has no `href` prop, and two screens passed one.** An unknown
prop falls through to the root element as an attribute, silently; on a `<tr>`
that renders perfectly and does nothing — so `/admin/network/addressing/{prefix}`
was a routed, tested, rendered screen that **nothing in the product linked
to**. A `<tr>` cannot be wrapped in an anchor, so the convention is a link in
the identity cell. `tests/Feature/ComponentPropsTest.php` refuses the mistake
now; a list of every prop of every primitive would rot, so it names only the
components where an ignored attribute is invisible.

**`class="font-mono"` on an `AppTextarea` lands on the wrapper**, so the
device-change form asked for "The configuration it should have" in monospace,
label and hint included. The primitive has a `mono` prop now. Same cascade
trap as `class="block"` on an `AppStatus`: a utility aimed at a primitive's
inside hits its outside — wrap, or give the primitive the prop.

**`['defaults'] + $attributes` in a test helper silently ignores every
override**, because `+` keeps the **left** operand's key. It made a helper
that looked parameterised and was not, and the symptom was a `can` flag that
would not go false.

**Just-in-time access is in** (`access_grants`, a section on Connect). A grant
is somebody holding one more thing than usual until a time, and it is read in
exactly one place: `ConnectController::authorizeConnect()` passes on the
permission **or** on a live grant.

**There is no state column, and that is the design.** Whether a grant is live
is a question about its own two timestamps, asked when somebody uses it — so a
scheduler that was down for three hours leaves nobody holding access they
should not have. The `access-grants` sweep still writes `revoked_at` with a
reason, because an operator reading the list wants to see that a grant *ended*
rather than infer it from a date; nothing depends on it having run. ADR 0031
applied to a permission rather than to an invoice.

**A grant only ever adds.** `GrantableCapability` has no member that takes
something away and must not gain one: a mechanism that could remove a
permission for a window is a mechanism for locking an operator out, and roles
already decide what people may do. That is also what makes asking the gate
everywhere safe — somebody who holds the permission never touches the table.

**Nobody grants themselves anything**, enforced in `AccessGrants` rather than
by the permission: a permission says who may grant and cannot say *to whom*.
The same rule `DecideNetworkChange` needed for approvals, for the same reason,
and the select on the screen leaves the person asking out so the form cannot
fail after being filled in.

**The window is bounded at both ends.** Under five minutes is a grant somebody
is about to give again; over `platform.network.max_grant_minutes` (twelve
hours) is a permission with extra steps, and this product has roles for those.

**An expiry has no actor, and the audit row says so.** `AccessGrants::revoke()`
takes a nullable staff user and writes `bySystem()` when there is none — a
record whose author was invented would be a record that lied about who acted.

**Phase G is complete.** The DCIM spine, hardware parts, the power path, remote
hands and the WordPress fleet's read half. `docs/architecture/phase-g-result.md`
records each decision; H to J are not started.

**Phase C is complete.** IPAM, the device contracts and the FortiGate module,
topology discovery, the guarded change workflow, just-in-time access and DDoS
events. `docs/architecture/phase-c-plan.md` records each decision.

**An attack is attributed through `ip_assignments`, at the moment it
started.** Every scrubbing vendor can say an address was hit with 40 Gbps of
NTP reflection; only this platform can say whose hosting account was on that
address *at the time* — and "at the time" is the part that needs the
append-only table rather than the current holder. An attack last Tuesday on an
address since handed to somebody else must not be attributed to its new
holder, which is exactly what a lookup of the present assignment would do.
That is what §5 made `ip_assignments` append-only for, and this is the first
thing that reads it.

**An attack on an address nobody held is kept**, with both attributions null.
A misconfigured scrubber, a range nobody recorded, a neighbour's address being
reported to us: each is a finding, and dropping the row would drop the
finding. The screen says so in words rather than drawing a dash, which would
read as missing data.

**An address is matched on its bytes, never on its text.** `2001:db8::1` and
`2001:0db8:0000:0000:0000:0000:0000:0001` are one address, and a text
comparison would attribute neither — the bug that makes an abuse report
unanswerable.

**`ddos_events` peaks are `decimal`, not float**, and that is not the money
rule bending: they are measurements, but a float answering 11.699999999 for a
reported 11.7 is a figure an operator cannot reconcile with the invoice for
the transit that carried it.

**A sweep's window comes from the rows.** `CollectDdosEvents` asks each source
for everything since the latest event it already reported, less a ten-minute
overlap — never "the last five minutes", which loses an afternoon permanently
the first time a worker is down for one. Re-reporting is free because the row
is keyed on the provider's own reference, and that is also how a running
attack's end and true peak arrive.

**`compact` on an `AppStatus` hides the word from everything but a screen
reader**, which is right where the label is already beside the mark and wrong
where the mark is the whole cell. The attacks screen drew a bare amber
triangle in its Customer column until it was looked at — "status is never
colour alone" applies to a glyph on its own too.

**Phase D has begun** (`docs/architecture/phase-d-plan.md`). Alerts are in;
incidents, the status page, SLA credits, postmortems, maintenance windows and
push are not.

**An alert is not an incident, and neither is a ticket.** An alert is a machine
observation with no opinion — it raises itself and clears itself, and nobody is
assigned to one. An incident is a human saying "this is a thing", with a state
somebody moves and a timeline somebody writes. A ticket is a conversation with
a customer (ADR 0030). Merging any two would either make the platform decide
when an observation becomes a human problem — which it cannot — or ask somebody
to acknowledge four hundred disk warnings a week, which is how people learn to
acknowledge without reading.

**There is deliberately no `acknowledged` state**, for that second reason. The
human act in this product is opening an incident, and an alert that never
became one is an alert nobody thought was worth one — which is a true and
useful thing for the list to say.

**Core raises alerts with no adapter configured at all.** Every
`AlertSubject` but `Metric` reads a table this platform has had since handoff
#1: the health checks, the adapter rows, the automation runs, the operations.
An operator who installs nothing still gets told when the queue backs up, a
provisioning job fails or the scheduler stops.

**Core ships no rules**, the same decision tax, dunning and placement got. An
installation that woke somebody at three in the morning because of a threshold
nobody chose is an installation whose alerts get turned off in a fortnight.

**`alerts.dedupe_token` exists because MariaDB treats nulls in a unique index
as distinct.** A key of `(rule, subject, cleared_at)` would allow two open rows
— both hold null there, and null never collides with null — so the index would
look as though it were doing the work and would not be. The token is `''` while
open and the alert's own id once cleared.

**Clearing is the half people forget.** A rule that only ever raised fills a
screen with things that stopped being true days ago, and an operator who has
learned the list is stale is one who does not read it. Every evaluation closes
the open alerts whose subject is no longer bad, and the row is kept.

**`for_minutes` is honoured without storing a series.** The alert's own
`first_seen_at` is the history: the row is created on the first bad observation
and only becomes `Raised` rather than `Suppressed` once it has been bad long
enough. That is why a spike lasting nine seconds reaches nobody.

**A stale reading is skipped, never alerted on.** A machine that stopped
reporting is a monitoring problem, not a disk that is 94% full, and raising the
last known value for ever would be this platform asserting something it no
longer knows.

**A severity scale must not wear another scale's words.** `AlertSeverity`'s
three members are distinguished by *when somebody deals with it* — "During the
day", "Now", "Customers affected" — and the first draft said "Worth a look",
which is already `health.states.degraded`. The severity column and the reading
column then sat side by side saying the same words about two different things.
Found by looking at the screen.

**`raised` and `cleared` had to join `status.ts`**, like every status word
before them: `statusTone()` drew the unknown mark (○) on an open alert until
they did. `raised` is `warning` rather than `critical` on purpose — the
severity column beside it already carries how bad, and toning the state by
badness would say the same thing twice in two columns.

**`:count times` reads as "1 times".** `useTranslations()` has no
`trans_choice`, so a count in the browser is a bare number under a column
header that says what it counts — the header does the work the sentence was
doing badly.

**Incidents are in** (`phase-d-plan.md` §15). An alert is a machine noticing;
an incident is a person saying so, and the two are deliberately different
records. Four things about it are the design rather than the implementation:

- **Opening one is Support's** (`reliability.incidents.manage`). The person
  answering "is it just me?" finds out first, and a platform where they had to
  go and find somebody senior to press the button is a platform where the first
  ten minutes of an outage are spent looking for that person.
- **An incident always has at least one update**, because opening writes it. A
  postmortem is written from the timeline, and a timeline that begins in the
  middle is a postmortem with a hole in it.
- **The state and the sentence are one act.** There is no route that moves an
  incident to `identified` without saying what was identified — that move is
  what makes a status page useless. `resolve()` is separate from `note()` for
  the opposite reason: it has a figure to freeze and a timestamp to write, and
  a second way to end an incident would be the one that forgot.
- **Resolving freezes the impact, and then the evidence stops moving too.** The
  figure is computed from the incident's own alerts through `ImpactSummary` and
  written to `incident_impacts`; attaching or detaching afterwards is refused,
  because a stored number beside rows it was not computed from is a number
  nobody can reconcile. The customer count is the **largest** single answer
  rather than a sum — two nodes under one customer would otherwise be counted
  twice. `started_at` is when the customer's world broke and `detected_at` is
  when anybody found out; the gap between them is what a postmortem is usually
  about, and `durationSeconds()` measures from the first because that is what
  an SLA measures from.

**A tone is not a status word, and `statusTone()` cannot read one.** The
vocabulary in `status.ts` maps *words* — `active`, `failed`, `raised` — and
`info`, `maintenance`, `neutral` and `unknown` are **tones**, in no list. So
`statusTone('info')` is `unknown`, and `IncidentState::Monitoring` drew ○ on a
real state. `asTone()` is the reader for a `*Tone` field the server already
decided (`AlertSeverity::tone()`, `IncidentState::tone()`); `statusTone()` stays
the reader for a status. The alerts screen had the same line and only worked
because `warning` and `critical` happen to be both.

**`AppCheckbox` takes `description`, and two screens passed it `hint`** — the
name every other input primitive uses for that sentence. Vue dropped it on a
`<div>`, so the explanation under the checkbox never appeared, on a form asking
whether to publish an incident to customers. `ComponentPropsTest` now derives
every primitive's props from its own `defineProps` and refuses a caller that
passes one primitive the prop of another, where the name is ours rather than
the browser's (`hint`, `tone`, `variant`, `loading` are ours; `type`,
`disabled` and `placeholder` are meant to fall through).

**It found a worse one immediately: `AppConfirm` never emitted `close`.** Every
screen in the product wires `:open="removing !== null"` beside
`@close="removing = null"`, and the component declared only `confirm` — so
backing out of a confirmation left the page pointing at the record while the
dialog's own model said shut, and because the prop never changed **the dialog
could not be opened again without reloading the page.** Press Cancel on a
delete, and delete stops working, on roughly forty screens. Nothing saw it: the
component's own tests mount it with props they control, and a browser pass
presses Confirm. `AppConfirm.test.ts` now mounts a *parent* wired the way a page
wires it — which is the only place the bug exists. The same sweep found the
prefix screen passing `:body`, `:loading` and `@cancel` where the component
takes `description`, `busy` and `close`: a release dialog with no sentence in
it, no busy state, and a Cancel that half worked.

**A `\b` in a regex written through a Bash heredoc becomes a backspace
character** (0x08), and the test still passes — matching nothing, silently. The
backslash-eating trap has produced a broken JSON manifest, an unterminated
comment and now a guard that guarded nothing. Anything with a backslash goes
through Write or Edit, and a new scanning test is worth running once against a
known offender before trusting a green result.

**A button is labelled with what it does** — found twice more here. "Post an
update" under a heading reading *Post an update* says nothing, and reading it
on a button that opens a *Resolve* dialog is a surprise: the label follows the
state that was chosen. On the list, the toggle, the heading and the submit all
said "Open an incident", so the submit is "Open it".

**A column of two bare numbers under one word is operator shorthand nobody
defined.** "0 / 0" under *Underneath* became a Customers column and a Recurring
column, each right-aligned and each empty until the figure is frozen. And a
severity word answers *when* — "Now", "During the day" — so it needs the column
header that asks the question: the incident's alert list is an `AppTable`
rather than a row of words for exactly that reason.

**The status page is in** (`/status`, `themes/storefront/core/views/status.blade.php`).
A banner, what is open, and ninety days behind it. Four decisions:

- **It is outside `EnforceMaintenanceMode`.** Maintenance mode closes the shop
  and the client area; closing the status page with them would take down the
  one page whose entire purpose is to be readable while something is wrong. A
  customer who goes there to ask whether anything is down, and is told the site
  is down for maintenance, has learned nothing they could not already see.
- **`PublicStatus` presents as well as reads, which is not the usual
  division.** Everywhere else the query fetches and a presenter drops what the
  reader should not see; here they are one class, because the reader is
  *everybody* and a field that escapes has escaped to the internet. It returns
  arrays rather than models so no template can reach through to a relation
  nobody meant to publish, and the private updates are filtered **in the
  query** — loading them and skipping them in a `@foreach` is one edit away
  from the page.
- **What is published is short and the test asserts the absences.** Not the
  impact — "47 customers, 12,400 EUR a month" tells the internet the size of
  the business and which outage was the expensive one. Not the alerts, which
  name hostnames. Not the operator who wrote the update, who did not agree to
  be named. Not even the reference, which is the seller's document number.
- **`PublicStatusLevel` is about the service, not the incident.** "All systems
  operational / Some systems are affected / A major outage is in progress" —
  derived from what is open, never stored. `AlertSeverity`'s words answer *when
  somebody deals with it*, which is the operator's question; printing
  "Customers affected" as a public headline would be operator vocabulary on a
  customer surface, the storefront's old mistake through a new door.

`noindex,follow` on it, because a status page that ranks for the company's own
name puts "major outage" at the top of a search result for months after the
outage ended.

**The good state is a sentence, not a blank space.** "All systems operational"
and "Nothing has gone wrong in the last 90 days" — a page whose healthy state
is an empty area is a page a customer cannot tell from one that failed to load.

**`latest()` is not an order when a second holds several rows.** `Incident::updates()`
ordered on `created_at` alone, which has a resolution of one second — and
several updates inside one minute is exactly what a busy incident looks like.
Two written in the same second came back in whatever order the database felt
like, which on a status page is a timeline that reads backwards. The id is a
ULID, so `orderByDesc('created_at')->orderByDesc('id')` settles the tie with
the truth rather than with luck. The browser found it; no test had ever written
two updates in one second.

**A public time needs its zone, and needs it once.** A customer in another
country reading "9:07 PM" does not know whose nine o'clock it is. It is said in
the banner — "As of 26 September 2026 22:01 UTC" — and every other time on the
page is on that clock; repeating it on nine lines was noise that stopped being
read by the third one. `app()->setLocale()` does not move Carbon's, so the
formatting goes through `->locale(app()->getLocale())` like `RenderTemplate`
does, or a Turkish page prints English month names.

**The state beside an incident's title said what the update under it said, word
for word.** `note()` moves the incident's state and writes the update in one
act, so the two can never differ — and the section heading already says whether
this is happening now or is over. The state that earns its place is the one on
each update, because that is the part that changed.

**`@php` belongs in no theme template.** The rule is "no raw PHP", and a
`@php` block is not refused by it — but a tone-to-class map written in one is a
colour chosen outside the design system, in a file a theme author may replace.
The controller hands the two class names over; the template only prints.

**SLA credits and postmortems are in**, which finishes §15 bar the maintenance
windows. Four decisions:

- **A credit is a credit note, and there is no second way to move money.**
  ADR 0023 froze the issued invoice and ADR 0024 made the ledger the truth, so
  `IssueSlaCredit` delegates every rule about the amount — the currency, the
  draft, what is left of the invoice — to `IssueCreditNote`, and adds one row
  saying which incident it was about. `sla_credits` is a link, not a second
  ledger; the amounts are copied onto it all the same, because a report of
  what outages cost last quarter must not move when somebody later credits the
  same invoice for something else.
- **Core does not work out how much.** An SLA is a contract this platform has
  never read: 99.9% with a 10% credit is one seller's terms, some credit the
  day and some the month. A percentage invented here would be a commercial
  promise made on a seller's behalf — the decision tax and dunning already
  made. The screen says so in as many words under the amount field.
- **What core *can* answer is who.** `AffectedCustomers` walks the incident's
  alerts to their nodes, the nodes to the services under them, and the services
  to their customers and invoices. That is the one thing in the conversation
  only this platform can do: every monitoring system can say a machine was
  down, and none of them knows that eleven services on it belonged to nine
  customers. It is computed **now** rather than read from the frozen figure,
  because an operator crediting a week later wants the customer's latest
  issued invoice; `IncidentImpact` stays frozen and answers *how many*.
- **A postmortem is the one editable thing on an incident.** The timeline is
  append-only because what was believed at half past two is evidence; a
  postmortem is a conclusion somebody revises when the third person reads it.
  Only once resolved, clearable (and clearing it clears `postmortem_at` — a
  date saying one was written, beside no postmortem, is a record contradicting
  itself), and never published: a seller who wants a public version writes it
  as a final public update.

**`reliability.credits.issue` is not Support's**, unlike opening an incident.
Finding out first and deciding what an outage is worth are different jobs. The
list of affected customers is not sent to somebody who cannot act on it either
— it is a list of who had a bad day.

**Ask for the password *before* a form, not on the way out of it.**
`auth.recent` redirects with a GET, so being challenged on submit throws away
the amount and the sentence already typed — and a money form is the worst
place in the product to lose what somebody wrote. Every other re-challenged
action here is a bare button press, which is why this had never come up. The
screen carries `can.confirmed`, and the button goes to a GET route that also
carries `auth.recent`: stale, and the middleware challenges and returns them;
fresh, and it redirects straight back. The POST keeps its own `auth.recent`,
because a page rendered fourteen minutes ago is not a lock.

**A column header names what is in a row, not what a strip counts.** The
affected-customers table borrowed `incidents.customers` and `incidents.services`
— the impact strip's labels — and ended up saying "Customers" above one
customer's name. It has `credit_customer` and `credit_affected` now.

**An escaped apostrophe inside a Bash heredoc is eaten like every other
backslash**, and `'a customer's invoice'` is then a PHP parse error in a
language file. Nothing caught it until the tests ran: `pint app` and `phpstan`
do not read `lang/`, and `pint --test` had last run before the edit. `php -l`
on a language file after a scripted edit costs nothing.

**Maintenance windows are in** (§16), and with them **the first notification
this product sends to operators**. The two arrived together on purpose: a
window that suppressed nothing would have been a setting read by nothing, and
`alert_rules.notify` had been exactly that since the alerts increment —
stored, drawn on the form, and consulted by no code at all.

- **A window suppresses the message, never the observation.** The alert is
  raised, counted and on the screen exactly as it would be, and
  `alerts.suppressed_by` names the window that held it. An operator asking
  "did anything happen during the maintenance" has to get the true answer, and
  a platform that dropped the reading could not give one. The maintenance
  screen shows how many each window held, because a window that held nothing
  either covered the wrong machines or the work went better than expected.
- **There is no state column**, and `phase-d-plan.md` §3's sketch of one is
  corrected rather than followed. Whether a window is running is a question
  about its own two timestamps, asked when somebody asks it — so a scheduler
  that was down for three hours cannot leave one marked "scheduled" while it is
  plainly happening. `access_grants` made the same call in Phase C. Being
  called off is the one thing a clock cannot say, so `cancelled_at` is a
  column, and cancelling a window that already ran is refused: the alerts it
  suppressed carry its id, and rewriting that would be rewriting what happened.
- **Empty node keys mean everywhere.** A datacentre power test is the ordinary
  window, and making an operator enumerate four hundred machines to express it
  is how a feature goes unused — so `covers()` is a method rather than an
  `in_array` at each call site, because the empty case is the one somebody
  writing the check by hand gets backwards.
- **The banner on the status page does not move for planned work.** "All
  systems operational" during a maintenance is the truth as far as a customer
  standing outside is concerned, and a page that went amber every Sunday at two
  is one nobody reads on a Monday. The window is announced above the incidents
  instead, and disappears from there the moment it is cancelled or ends.

`AlertRaised` is a domain event and `SendAlertNotifications` is the listener,
which is ADR 0029 exactly: the sweep that noticed announces, and one place
decides who hears. Three rules in it — a window sends nothing, a **warning
interrupts nobody** (`AlertSeverity::interrupts()`, because a warning that woke
people is a warning they turn off and it takes the criticals with it), and
staff get it, all of them, because there is no on-call rotation here and §6 of
the plan says inventing half of one would be worse than leaving it to the rota
people already keep.

**`NotificationEvent::AlertRaised` is transactional and its audience is
`Staff`** — the first member of either kind. Somebody woken because a disk is
full cannot have opted out of it, and staff preferences are a role rather than
a checkbox on an account.

**`composer stan`, not `./vendor/bin/phpstan`.** The bare binary crashed today
at 128M — the failure CLAUDE.md already predicted, arriving the moment the
codebase crossed the line. The symptom is "Child process error … reached
configured PHP memory limit" rather than an assertion, so it reads like an
environment problem and is not one.

**A date range printed with `toLocaleString()` on both ends** said
"30.09.2026 03:01:05 — 30.09.2026 07:01:05": the date twice, and seconds on
work planned for next Tuesday. A window inside one day prints its date once,
and nothing here is scheduled to the second.

**The submit button matched its heading for the third time.** The toggle, the
section title and the submit all read "Plan a window". It is the same fix as
the incidents screens — a button says what pressing it does — and it is worth
grepping a new form for the heading's own words before calling it done.

**A notification's link is built in one place** (`DeepLink`), and §26's deep
links are done. The bug it was written for: `SendEventNotifications` built
`/client/orders/{ulid}` for a route that looks an order up by its **number**,
so **the first message this platform ever sends a customer — their order
confirmation — arrived with a link that answered 404.** Everything passed. The
message was sent, the delivery row was written, and the string inside it was
never asked to resolve.

`tests/Feature/DeepLinkTest.php` does not assert the shape of a URL: it
**opens** every one of them, as the person who would have received it, and
expects the page. A path that exists with the wrong key in it still 404s,
which is the whole failure — and the test was checked against the original bug
before being trusted. It also refuses a listener that builds a `/client` or
`/admin` URL by hand, because the other half of this mistake is the one that
leaks rather than 404s: a customer handed an admin link.

**A test that opens a portal page needs the contact to hold a portal role**,
or it drives a 403 and `assertOk()` fails for a reason that looks like the
thing under test. It has now bitten twice.

**There is deliberately no `NotificationChannel::Push`.** §26 asks for a push
channel, and a member nothing implements is a member nothing can set —
`ChannelRegistry` would hold no entry for it, `Notifier` would deliver
nothing, and `notification_deliveries` would record nothing, which is the
`AddonStatus` rule through a different door. What "push" means for an operator
being woken is already `Sms` and `Chat` (SDK 1.3), both of which a module
implements. Web push is a subscription table, a VAPID keypair and a service
worker; it is real work and the provider half belongs in a module, so it is
left undone and said so here rather than half-built.

**Phase D is complete** (`docs/architecture/phase-d-result.md`): alerts,
incidents, impact, the status page, SLA credits, postmortems, maintenance
windows, the operations calendar and deep links. The push channel is declined
with its reason recorded rather than half-built.

**The operations calendar is a list of days, not a grid of boxes.** A grid is
what a calendar looks like; a list is what one is for — and it survives the
ordinary month, in which nothing happened at all. Thirty empty boxes say less
than one sentence does, and the two days that matter are lost among them.

**An entry that spans days is repeated on each of them, and the repeats must
not say the same thing.** A six-day incident printed its start time — "03:32"
— on all six days, which reads as though it began again each morning. The day
it started says when; the days after say it was already running. The browser
found it the moment seed data covered more than one day, which is another
reason throwaway data should be shaped like real data rather than like the
smallest thing that renders.

**A month goes in the address bar, not in component state.** Somebody looking
at the 14th of July is usually about to send that month to a colleague. A
parameter that does not parse falls back to this month rather than refusing:
they edited the URL, and the useful answer is the month they are standing in.

**Phase E has begun** (`docs/architecture/phase-e-plan.md`), and the abuse desk
is in — §13's cases, the correlation chain, guarded actions and evidence with
a retention clock.

**An abuse case is the third record in this family and points the other way.**
An incident is the platform's fault; a ticket is a conversation; a case is the
customer's fault, or their compromised account's. It ends in a **decision**
rather than in "resolved": `actioned`, `no_action` and `rejected` are three
different answers, and a word that flattened them would tell the next reader
nothing.

**Attribution is at the moment the complaint is about, never now.** A report
about an address last Tuesday belongs to whoever held it last Tuesday;
attributing it to today's holder is how an innocent customer is suspended for
somebody else's spam. `ip_assignments` is append-only for exactly this, and
the walk now lives in **one** place (`AttributeReport`) — `RecordDdosEvent`
grew it privately in Phase C and calls this instead, because two copies would
eventually disagree about the case that matters: an assignment released at the
very second of the report.

**A case this platform cannot attribute is kept**, with a sentence on the
screen saying why rather than a dash a reader would take for missing data. An
address in a range nobody recorded and a sender who is simply wrong are both
findings, and somebody still has to answer them.

**Every guarded action is a person pressing a button, and three of the four
land in `manual`.** Only suspension has a seam, and it calls
`TransitionService` rather than writing a column — two places that can suspend
is one too many. Recording a decision this platform cannot carry out, and
saying so on the screen, is the `Manual*` adapters' honesty applied to a
decision about a customer; an action that silently did nothing would be worse.
Nothing is automatic and nothing should be: a shared address, a forwarded
newsletter and a competitor's complaint all look like the real thing.

**`retain_until` is the first column in this product whose job is to make
something be forgotten**, and `AutomationTask::AbuseRetention` is the first
task whose job is to delete. Everything else here is append-only on principle;
this is the deliberate exception, because a complaint holds a **third party's**
data. Core keeps a reference — an id, a URL, a hash, a bounded excerpt — never
a mail body, a full log or a disk image. The deadline is written when the
evidence is captured and never recomputed, so shortening the policy in March
does not retroactively delete what was kept under January's terms. What is
deleted is the reference; the case, its timeline and the decision all stay.

**The audit row for a capture deliberately omits the reference.** An audit log
is the one table nothing deletes from, so putting the third party's data in it
would have defeated the whole arrangement. The kind and the retention go in;
the thing itself does not.

**`case` is a reserved word in JavaScript.** A prop named `case` makes every
`case.foo` in a Vue template a parse error — nineteen of them, plus nine
TypeScript errors, from one prop name. It is `abuseCase` now. Worth knowing
before naming a prop after a domain noun that is also a keyword: `class`,
`default`, `new`, `delete` and `for` are the others waiting.

**`FrontEndTranslationsTest` caught the new screens printing their own keys**
before the browser pass had finished looking at them — `security` was not in
the published allow-list. The designed symptom worked and the guard worked;
the browser simply got there first this time.

**A column header borrowed from elsewhere, for the third time.** The actions
table used the list's *Whose* column for the operator who decided, so a staff
member's name sat under a heading that means the customer. It has
`act_decider` now. The rule is the one the affected-customers table taught:
a column heading names what is in each row, and borrowing one that fitted
somewhere else is how a screen ends up lying quietly.

**An empty required dropdown with a button under it, again.** A case nobody
could attribute has no services, so "which service" was an empty select above
an enabled Act button — the server would refuse on a field whose list was
empty. It says why instead, and the button is disabled. Third time this shape
has been found; it is worth checking any `AppSelect` whose options come from a
relation that can legitimately be empty.

**The certificate fleet is in** (§8). Discovered, never issued: core reads
what is deployed and answers the questions that need no private key — what
expires soon, what chain is incomplete, whose customer is affected.
`CertificateProvider` is the contract (SDK **1.5**, additive) and
`Capability::CertificateIssueWrite` and `CertificateDeployWrite` deliberately
have **no method on it**, for the reason the firewall's write did: obtaining a
certificate has an account key behind it and deploying one changes what every
visitor is served, so both belong behind a workflow rather than behind a
method anything could call.

- **The fingerprint is the identity, not the common name.** One name is served
  by four certificates over a year and two names by one; a row keyed on the
  name would collapse the renewals into each other and lose exactly the
  history somebody wants when a renewal silently failed — and an *alert* keyed
  on it would look like the same alert clearing and reopening at every
  renewal.
- **`not_after` is never computed.** It comes from the certificate and is not
  defaulted or adjusted anywhere: an expiry this installation guessed would be
  worse than none, because somebody would act on it.
- **`chain_ok` is nullable and the null means "nobody looked".** An adapter
  reading a file off disk cannot say what a client would be served, and
  drawing that as a tick would be the platform asserting something it does not
  know. The column has three states on the screen for the same reason.
- **A certificate that stops being reported is retired, not deleted**, and
  **only by the source that wrote it** — retiring by organization would take a
  second adapter's fleet with it every time this one ran.
- **Attribution refuses ambiguity.** A certificate naming `shop.example.com`
  belongs to whoever holds `example.com`; a wildcard covering four customers'
  subdomains belongs to none of them. The `RecordSamples::byHostname()` rule
  again: attached to the wrong customer is worse than attached to nobody.
- **A wildcard covers one label and no more.** `*.example.com` is
  `a.example.com`, not `a.b.example.com` and not `example.com`. Being lenient
  here would have the platform call a name covered that a browser refuses,
  which is the one answer worse than no answer.

**Expiry is an `AlertSubject`, not a constant.** Thirty days is right for a
business renewing by hand and absurd for one on ACME with a fortnight's
lifetime, so the threshold is written as a rule and core ships none — the
decision tax, dunning and placement all made. The gatherer emits **every**
live certificate including expired ones with a negative figure, because
"below 14" has to catch "minus 3": a certificate that lapsed last night is the
one somebody most needs to hear about. The screen's own thirty days groups a
list and never decides whether anybody is told.

**A fourth borrowed label.** The chain column reused "In date", which is about
expiry, so an answer about time sat under a heading about completeness. That
is four in one session — the affected-customers table, the actions table, the
abuse section heading, and this. It is now worth a habit: when adding a
column, check that its wording was written *for* that column.

**`Domain` has no `service_id`** — a domain is not a service (ADR 0028) and
does not carry one. A certificate's link to a service, when it has one, comes
from the node it was found on rather than sideways from the name it covers.

**Zone health and the DNS contract are in** (§8), which unblocks DNS in
`integration-modules-plan.md` — it listed DNS as waiting on exactly this.

**Core owns the checks because they are RFCs, not opinions.** "Does this
domain have exactly one SPF record" is RFC 7208 §3.2 and has one right
answer; fetching the records is the only part that differs between
PowerDNS, BIND, Cloudflare and Route 53, so that is the only part that is an
adapter. `InspectZone` is **pure** — a zone in, findings out, no database and
no adapter — which is the only way these rules can be trusted before a real
provider has ever answered.

**What core refuses to have an opinion about is as deliberate as what it
checks.** It does not grade a DMARC policy (`p=none` is what most people
deploy on purpose for months), does not decide whether a domain ought to have
mail, and has no views about TTLs. A findings list full of things that are
fine is a list an operator stops reading — and then misses the `+all`.

**A finding raises and clears like an alert**, kept once cleared, with
`cleared_token` for the MariaDB-nulls-are-distinct reason `alerts.dedupe_token`
exists. It is keyed per **source** as well as per check: two providers holding
one zone is a real configuration during a migration, and "the old provider
still says this" is exactly the finding somebody wants.

**A sweep that could not read a zone clears nothing.** An adapter that is
down must not look like a zone that was suddenly fixed — clearing on a failed
read is the bug that makes a findings list untrustworthy. And core asks about
**its own domains**, never the provider's zone list: a provider authoritative
for four thousand names would otherwise have this platform producing findings
for customers it does not have.

**`FindingSeverity` is its own enum and not `AlertSeverity`.** A test caught
it: `AlertSeverity::from('info')` throws, because that enum's three members
are distinguished by *when somebody is interrupted* — and a zone finding never
interrupts anybody, it sits on a list until an operator reads it. Borrowing it
would have been Phase D's "a severity scale must not wear another scale's
words" arriving from the other direction. Two members, and the line between
them is whether the zone is **objectively broken**.

**`DnsZone::textAt()` strips the quoting a zone file adds**, because a
provider that round-trips through one hands back `"v=spf1 -all"` and a check
that missed the quoted form would report every zone on that provider as
having no SPF. Long TXT values arrive split into quoted chunks — that is how
DKIM keys are carried — so the chunks are joined before anything reads them.

**The bare `all` in an SPF record is the one people write by accident**,
because `+` is the default qualifier and reads like it means nothing. Both
forms are caught; `-all` and `~all` are the whole point of SPF and must never
be flagged, which is its own test.

**`Capability::DnsRecordWrite` has no method on the contract**, the third time
this pattern appears after the firewall and the certificate. A bulk record
change is how a business disappears from the internet for four hours, and it
belongs behind §6's guarded workflow rather than behind a method anything
could call.

**An unused method is the same smell as an unused setting.** A `checks()`
helper was written on the controller "for a screen that wants to name them",
with a docblock rationalising why it was there. Nothing called it. Deleted
rather than justified — if the docblock has to argue for the code, that is the
answer.

**Phase E is complete** (`docs/architecture/phase-e-result.md`): the abuse desk,
the certificate fleet, zone health, mail operations as telemetry and sending
reputation. F to J are not started. SDK **1.6** — `AdapterArea::Mail` and
`Capability::ReputationRead`, both additive.

**Mail is numbers, and that is the whole of it** (§13). Eight `MetricKind`
members — queue depth, deferred, held, delivered, rejected, bounce rate,
authentication failures, spam score — arriving through the normalizer that
already exists, landing on the Telemetry screen that already exists, alerted on
by the rules that already exist. §6 is why there is nothing more: the series
belongs in the specialist backend and core keeps the present. `MailDeferred` is
separate from `MailQueueDepth` because they fail differently — a deep queue is a
busy hour, and a deep *deferred* queue is somebody refusing to accept mail from
you. `MailDelivered` is the one where more is better, and a screen drawing it red
at its busiest hour is a screen nobody trusts.

**`mail_queue` and `deferred` were aliases of `QueueDepth` and are not any
more.** They are a mail server's queue, and mixing it with this installation's
own job queue on one metric is two very different outages drawn as one line. The
bare words another vendor also uses — `delivered`, `rejected`, `held` — are left
out of the alias table on purpose: an alias that is right for one vendor and
wrong for another is worse than an unmapped metric, which the Telemetry screen
can at least show as unmapped.

**A blocklist listing is not a metric**, which is why it is a row rather than a
number. It is a yes-or-no fact about one address on one list, with a reason
somebody wrote and a way to ask for it to be lifted; storing it as a figure
would lose every part an operator needs to act. `reason` is the blocklist's own
words, never translated or paraphrased — it is evidence in a conversation with
somebody else, and paraphrasing evidence is how a delisting request gets
refused. `delistUrl` is the field a platform is most likely to leave out and the
one that matters most in practice: an operator who has found the listing still
has to find the form.

**Nothing delists.** Asking a blocklist to lift a listing is a form with a human
on the other end, usually a captcha, and sometimes a promise about what has been
fixed. `ReputationProvider` has one method, there is no `ReputationWrite`
capability, and the screen carries the list's own link instead — a button that
claimed otherwise would lie.

`reputation_listings` is the third table with the raise-and-clear shape, after
`alerts` and `zone_findings`, and the third to need a `cleared_token` for the
same MariaDB reason. Attribution is taken **once, when the listing is first
seen**, through the shared `AttributeReport` — re-attributing on every sweep
would hand Tuesday's listing to whoever holds the address today, which is the
one mistake this family must not make. An address that will not parse is counted
and dropped rather than stored as text: a row whose address cannot be compared
is a row nothing can attribute and nothing can clear.

**A sweep with no source configured must clear nothing.** `CheckReputation`
skips an organization with no addresses rather than handing an adapter an empty
list, because recording an empty answer would clear every open listing on the
first run after somebody deleted a prefix. Same reason `InspectZones` clears
nothing on a failed read.

**A `match` with no default fails loudly at a call site and silently inside a
sweep.** `AlertSubject` has two of them — `needsTarget()` and `isNumeric()` —
and adding a member to one and not the other meant the rule threw inside
`EvaluateAlerts`, where "one rule failing never stops the rest" caught it: no
alert, no error, nothing in the run record. When adding an enum member, grep for
every `match ($this)` on that enum rather than fixing the one the compiler
happened to point at.

**`SystemRoleSeeder` changed a role's permission set and never flushed the
permission cache.** `CreateRole`, `UpdateRole`, `DeleteRole` and
`SyncPermissions` all do, and `PermissionCache`'s own docblock says stale
authorization is never acceptable. This is the seeder an *upgrade* runs, so a
phase that gives Support a new permission gave it to a role whose holders went
on being refused for the cache's fifteen minutes — a 403 on a screen the
operator can see the permission for in the role editor, with nothing saying why.
Found because a new screen refused an account that held its permission.

**No test in this suite could have caught that**, because `phpunit.xml` sets
`ACCESS_CACHE_TTL` to 0: `PermissionCache::remember()` calls its resolver every
time and the cache is disabled in every test there is. That is the right default
for a suite about authorization — a cached answer would hide a missing grant —
and it means the one test *about* the cache has to rebind `PermissionCache` with
a real TTL itself. It does, and it was checked against the bug before being
trusted.

**A permission a phase declares reaches nobody until the roles are re-seeded.**
`platform:permissions:sync` creates the row; `db:seed --class=SystemRoleSeeder`
is what puts it on Administrator and Support. Both, in that order, after a
migration — and on a development box the browser then wants
`php artisan cache:clear` if the seeder ran before this fix.

`tools/design-review.mjs` takes its page list from `DESIGN_PATHS` as JSON —
`DESIGN_ONLY` only filters that list, so it renders nothing on its own. A query
string works (`/admin/security/reputation?all=1`), which is how a filtered view
gets captured.

**Phase F has begun** (`docs/architecture/phase-f-plan.md`), and backup coverage
is the first thing in it. F is the data platform: backup, storage, database and
cache telemetry, load balancers with guarded drain, hypervisors and BMC with
guarded power, and the metering contract. The Migration Center is explicitly
not in it — eight resumable steps beside seven adapter families would be two
phases wearing one name.

**Core never takes a backup and never will**, which `docs/operations/` has said
since handoff #1. What core can do is the thing no backup vendor can: Veeam
knows what it backs up, and only this installation knows what it sold. The
difference — which running services nothing is protecting — is the reason the
family is worth building, and it is the tab the screen opens on.

**`last_good_at` is the number, never the last outcome.** A job that failed last
night is a warning; a job that has succeeded every night for a month against a
resource deleted three weeks ago is a lie, and a green tick beside it is worse
than a red cross. It is also **not** `last_run_at`: a screen sorted on the run
would put the most broken thing on the estate at the top looking fine.

**The last good copy only ever moves forward.** A source that reports null for a
run it could not describe must not erase what it said yesterday — "there has
never been a good copy" and "I cannot tell you about the last one" are different
answers, and writing the first when it meant the second makes a protected
service look abandoned.

**Unprotected and stale are different, and that distinction is the safety
rail.** A source that is merely unreachable reports nothing, and "nothing" read
naively means every customer has lost their backups — a screen that said so at
three in the morning would be believed once and ignored for ever. Stale is a
protection that exists and is not succeeding; unprotected is a service no live
protection names. The contract says a failed read must **throw** rather than
return `[]`, `CollectProtections` catches it and never calls the recorder, and a
test pins that a sweep with no source configured retires nothing.

**Each figure on that screen counts exactly what its list shows**, and they are
not the same unit: unprotected counts *services* and stale and protected count
*protections*. Pressing a count filters to a list, so a figure that did not match
the rows under it reads as a bug — which is what the first browser pass showed,
"1" over three rows. They do not sum to anything and were never meant to.

**A suspended service still holds the customer's data** and is the one most
likely to be deleted next, so it expects a backup. So do grace-period and
cancel-pending. Expecting one only of `active` would leave out exactly the
services somebody is about to lose.

**A protection that matches no service is kept**, and the row says so in words.
It is either a backup job for a customer who left — worth knowing, and worth
money — or a name this platform spells differently. Matching is by name against
the service's domain then its name, lower-cased, and **ambiguity is refused**:
that is `RecordSamples::byHostname()`'s rule again, because a protection attached
to the wrong service is what somebody reads before telling a customer their site
is backed up.

**A tone is a word `status.ts` knows, and `success` is not one of them.**
`BackupOutcome::Succeeded` returned `success` where every other enum in this
product returns `healthy`, so `asTone()` fell through to `unknown` and a backup
that had worked drew ○. Nothing caught it — the value was a string, the test
asserted the field was sent, and only the screenshot showed the glyph.
`VocabularyTest` now walks every enum under `app/Domain` with a `tone()`, finds
them by reading the source rather than from a list somebody maintains, and
checks each against the `TONES` array parsed out of `status.ts`. One list of
tone words, which is the thing it is guarding.

**`AppStat` sits in a `flex flex-wrap gap-3`, not a full-width grid**, and its
tone is conditional: a zero is `neutral`, never red. Nothing unprotected is the
good answer, and a screen that drew it in danger would teach an operator to stop
reading the colour. `Admin/Services/Index.vue` is the worked example.

**"0 days old" beside a date reads as a figure that failed to load**, not as a
backup that ran this morning. The screen says "Today" instead.

`Illuminate\Contracts\Pagination\LengthAwarePaginator` does not declare
`linkCollection()`. A read model returning the contract makes every controller
that pages it fail PHPStan; return the concrete `Illuminate\Pagination\LengthAwarePaginator`.
And a controller that builds one of several differently-typed pages wants a
generic `page(LengthAwarePaginator $page, callable $row)` helper rather than a
`match` that hands a union to `array_map`.

**SDK 1.7**: `BackupProvider`, a contract that did not exist. Minor, because a
contract nothing has implemented cannot have been implemented — the same
reasoning `CertificateProvider` and `DnsProvider` got at 1.5.

**Sixteen screens added in phases C to F had never joined
`docs/design/propagation.md`**, although that file says in as many words that a
screen added after the conversion gets a row. Each had been driven in a browser
as part of its own increment, so the evidence was there and the record was not —
which is exactly the drift a tracker exists to stop. They are listed now, in
their own section, with the phase that added them.

**A storage volume is a graph node, not a table**, and `phase-f-plan.md` §3
records the correction. `ResourceKind`'s own docblock names "a storage volume"
as its example of a thing a *module* owns — core owns four kinds because it
owns four kinds of row. Everything a table would have bought is already in the
graph: pool contains volume, server hosts volume, server contains service, so
§9's "attached workloads" is the walk `ImpactSummary` already does. And the one
thing a table would have added — an edge from a volume to a customer's service —
is refused anyway, because the ends are in different subtrees (ADR 0043). Same
wall IPAM hit in Phase C, same answer.

**Capacity is telemetry, never a column.** `DiscoverStorage` writes
`disk.total` and `disk.used` through `RecordSamples`, which is what
`CapacityForecast` reads and what the Telemetry screen already draws. A
`used_bytes` attribute on the node would be a second copy of a number that
changes every hour, and the daily rollup would never see it. Nothing is
written for a figure the source did not give: a pool with no total is an S3
bucket, and a zero would draw it as full.

**`modules/infracms/storage-ceph`** is the first storage adapter, and four
things about Ceph only a careful read of its API turns up, each pinned by a
test:

- **A pool's total is not reported; it is derived.** Ceph answers `bytes_used`
  and `max_avail`, and `max_avail` is what is left *for that pool* after
  replication and the fullest OSD — so the total is the sum, and it moves when
  a *different* pool grows. True of Ceph and surprising to everybody once.
- **A pool's health is its own `pg_status`, not the cluster's `health`.**
  `HEALTH_WARN` is cluster-wide and names no pool; a pool whose PGs are
  `active+clean` is healthy inside a warning cluster, and reading the cluster
  status would make every pool look broken because one OSD is near full. The
  PG states are compound (`active+undersized+degraded`), so the check looks for
  words rather than matching whole strings, and **worse wins** — a pool that is
  both degraded and has an inactive PG is critical.
- **An RBD image reports no health of its own**, so it is `Unknown` rather than
  inheriting its pool's. An image on a degraded pool is not itself degraded.
- **The image endpoint answers grouped by pool** — `{pool_name, value: [...]}`.
  Reading it as a flat list answers nothing at all, silently, which looks
  exactly like an empty cluster.

**A volume names its pool differently from how the pool names itself.** Ceph's
image list says `pool_name` and a pool is keyed here by its **id**, because an
id survives a rename and a name does not — the serial-versus-hostname rule
again. Without resolving one to the other every volume named a pool nothing
had, and the containment edge was simply never written: the sweep did exactly
what it should (no node, no edge) and the result was a graph with no
relationships in it. When an adapter's two endpoints identify the same object
differently, the *adapter* reconciles them; core writing an edge on a guess
would be worse.

**`StorageHealth::Degraded` is the member a three-state scale loses.** A pool
rebuilding after a disk failure is serving every read and is one more failure
from losing data — that is the window somebody can act in, and collapsing it
into either neighbour throws the window away. Same reasoning as
`BackupOutcome::Warning`.

**A cluster's own bad health is not an adapter failure.** `CephProvider::health()`
answers `Ok` on `HEALTH_WARN`: the adapter answered perfectly and the cluster has
something to say, which belongs on the pools it affects and on an alert rule. An
adapter marked failing because a cluster is rebalancing is one an operator learns
to ignore.

**Only what the existing metric kinds cannot say gets a new one.** Database and
cache telemetry added exactly three — `db.slow_queries`, `db.deadlocks`,
`cache.evictions` — because connections are `Sessions`, queries a second are
`RequestRate`, replication is `ReplicationLag`, the hit ratio is
`CacheHitRatio` and memory is `MemoryUsed`. A second name for any of them would
split one question across two charts.

**Every metric name in this product printed its own translation key**, on the
Telemetry screen, in the Explorer's drawer, on the capacity panel and inside an
alert's own label, in both languages, since Phase A. `MetricKind`'s values have
dots in them, so `__('infrastructure.metrics.disk.total')` asks the translator
to walk three levels of nesting and comes back with the path — the permission-
slug trap through a fourth door. The wording was present and correct in `lang/`
the whole time; nothing read it.

`App\Application\Infrastructure\MetricNames` is the reader, beside
`PermissionNames` and `CapabilityNames`. `VocabularyTest` asks it
`isWorded()` for every `MetricKind` in both locales rather than comparing a
label against a key, because the derived fallback (`disk.iops` → "Disk iops")
is legible enough that a comparison would pass for every metric nobody had
named.

**The comment above one of those call sites described the trap exactly and the
code under it fell into it anyway.** A rule somebody remembers is not a guard;
a class is.

**`CapacityPanelTest` asserted the bug.** It compared `metricLabel` against the
same broken `__()` call, so both sides printed the key and the test passed. An
assertion that builds its expectation the way the code builds its answer is an
assertion that can only agree with itself — spell the expected string out.

**The Explorer's drawer received every discovered fact and drew none of them.**
`attributes` has been in the node payload since Phase A — a device's model,
serial and firmware, a port's speed, MAC and VLAN, and now a Ceph pool's health
and replica count — declared in the props interface and rendered nowhere. It has
a "What it reported" section now. Wording comes from
`infrastructure.explorer.attributes.<key>` with a **humanised key as the
fallback**, because an adapter's key is not core's to name; a value is worded
only where the key holds an enum (`health`, `state`), so a model number stays
the adapter's own word.

**The status column and what an adapter reported are different questions.** A
node's `health` is derived by the telemetry normalizer and means "are the
readings fresh"; a Ceph pool rebuilding after a disk failure reports perfectly
and is degraded. Writing a discovered condition into that column would lose the
staleness answer and be overwritten by the next sample anyway.

**PHPStan now catches the `static fn` that reaches `$this`** — the trap Phase 9
shipped once with every test passing, because no test loaded that page. Two of
them appeared the moment a presenter needed an injected reader.

**Load balancers are in, with the first write this family ships.** A drain is
reversible where a firewall policy is not, so it is a guarded **action** —
`infrastructure.drain` plus the password challenge, the permission above
`auth.recent` on the route — rather than a guarded **change** needing a second
person at two in the morning. `LoadBalancerWriter` is a separate interface from
`LoadBalancerProvider` so an adapter that can only read says so by not
implementing it, the reason `NetworkDeviceWriter` is its own.

**`DrainBackend` reads the backend back.** The whole point of the action is the
state it produces, and a balancer that accepted the command and then cannot
describe the backend is `BackendRefused::unverifiable()` — not a success, because
the operator is about to reboot a machine on the strength of the answer. The
state it reports is written onto the node, so the list they return to shows what
they did rather than what the last hourly sweep saw.

**Nothing waits.** Telling a balancer to stop sending new connections takes a
second; waiting for the open ones to finish is the operator's job. A method that
blocked until the count reached zero would be a request that hung for an hour.
That is also why `activeConnections` is null rather than zero when a balancer
does not report it: acting on an invented zero is how somebody reboots a server
that is still serving.

**`BackendState::Draining` is the member that earns the enum** — neither up nor
down, serving what it has and taking nothing new — and it is toned
`maintenance` rather than `warning`, because it is a thing somebody chose.
`Disabled` is somebody's decision and `Down` is a health check's verdict, the
same distinction `PortState` draws.

**HAProxy reports two states and the administrative one wins.** `admin_state`
(`ready`/`drain`/`maint`) and `operational_state` (`up`/`down`/`stopping`) are
separate fields: a server somebody drained is still operationally up and must
not read as taking traffic. `stopping` is the operational way of saying
draining. And a drain is a `PUT` of **one field** — sending the server's other
fields back would overwrite whatever somebody changed in between.

**The Data Plane API's two halves answer different shapes**: the configuration
endpoints wrap their answer in `{_version, data}` and the runtime ones answer a
bare list. Reading the envelope as the list answers nothing, silently.

**The `resource_adapters` row does not exist until the registry syncs it**,
which happens the first time anything asks the registry for an organization's
adapters. A test that turns `writes_enabled` on before the first sweep updates
nothing and silently does not happen — which is why the helper asserts the
update matched one row.

**`auth.recent`'s session key is `auth.confirmed_at`**, not Laravel's
`auth.password_confirmed_at`. A test that sets the wrong one gets a 302 to the
password screen and reads as a routing bug.

**A translation key nothing reads is the same trap as a setting nothing reads.**
`loadbalancing.reason_hint` was written for a hint `AppConfirm` already draws
itself; it was found by looking at the dialog, and deleted. Before adding
wording beside a primitive, check what the primitive already says.

**Virtual machines are in, with the most consequential button in the product.**
A power action does not stop a customer's service; it stops the machine several
customers are on. Level 4 — the reason *plus* the machine's own name typed out,
checked on the server as well as in the dialog — `infrastructure.power` above
`auth.recent` on the route, and every attempt on the audit row including the
ones that failed.

**`ChangeMachinePower` reads the state from the hypervisor at the moment of the
call, not from the page.** A page an operator has had open for ten minutes is a
page somebody else may have acted on, and a `power_off` sent to a machine that
was started thirty seconds ago is not harmless. Same re-read
`ApplyNetworkChange` does of a device's fingerprint, for a smaller diff and the
same reason.

**Stopping something already stopped is refused, not treated as
`already_done`** — the opposite of provisioning (ADR 0026) and deliberate. A
provisioning retry that finds the account already created has found what it
wanted; an operator pressing Shut down on a machine that is already off is
looking at a page that does not match the world, and saying so is the useful
answer.

**`PowerAction` has no `Reset`.** A reset is a power-off and a power-on with no
pause between them, and a single button that did both would hide the moment at
which somebody could still change their mind. `Shutdown` and `PowerOff` are kept
apart because one asks the operating system and one cuts the power — on the one
action where the choice *is* the decision, an operator must be able to make it.
Proxmox agrees: they are different endpoints, not one with a flag.

**A paused Proxmox machine reports `running`, with `qmpstatus: paused`.**
Reading only `status` shows it as serving traffic — and it is exactly the
machine holding all its memory and answering nothing, which is the most common
way a cluster runs out of RAM with half its guests idle.

**A VMID is unique in the cluster and every call that acts on a machine needs
the node in the path**, so a machine's key is `node/type/vmid`. A bare vmid
would need a lookup before every action; a bare name would move.

**A declared `default` on a module config field meant nothing.**
`SaveModuleConfig` read `$input[$key] ?? null` and cast it, so a boolean nobody
mentioned became **false** rather than its declared default — and
`ModuleController::presentConfig()` sent `null` for any field with nothing
stored, so a freshly installed module showed an unchecked box beside
`default: true`. Every `default: true` in every manifest was decorative, and
the one that mattered was `verify_tls`: **a module configured without naming it
had certificate verification silently turned off.** The distinction is
**absent** rather than falsy — an unchecked box posts `false` and must stay
false — and both halves are pinned by a test in `ModuleScreenTest`. Found
because a container list came back empty.

**Never interpolate a button's label into a question.** ":name Shut down?" is
not a sentence in English and is worse in Turkish, where the word order is
different: a label is a button word and a title is a sentence. Each power
action has its own title *and* its own body, because the four are four
different promises.

**`AppMenu`'s slot exposes `close`, and a row that opens a dialog has to call
it.** Without it the menu stays open behind the dialog's scrim, which reads as
two things having happened.

**Two `pest` runs against one test database is 96 failures that mean nothing.**
`RefreshDatabase` truncates between tests, so a second run started while the
first is still going rolls the first one's rows out from under it. The failures
have no common cause and no stack trace worth reading. Start a run only when no
other is going — the same rule `db:wipe --env=testing` already carries.

**Usage metering is in, and Phase F is complete**
(`docs/architecture/phase-f-plan.md`). Backup coverage, storage, load
balancers with guarded drain, hypervisors with guarded power, and the metering
contract with its snapshots.

**A meter answers quantities and the seller sets the price.** `UsageMeter`
returns a `UsageReading` — a number and a unit — because a source that
returned money would be a module setting prices, which is the one thing
metering must not be able to do. The rate lives on `usage_meters` rather than
in the catalog, and that is not a shortcut: a product price is a cycle and a
currency (the ADR 0021 matrix), and a usage rate is per service, per meter,
with an allowance — three dimensions that matrix cannot express, and two
customers on one product routinely have different allowances.

**The meter declares the billing unit and the source answers in it.** A
reading in another unit is refused, never converted — `RecordSamples`' rule
about a unit from the wrong dimension, applied where the consequence is money.
A conversion here is a rounding error nobody can find between the invoice and
the screen.

**A snapshot is append-only and an invoice line quotes it.** §25 asks for
immutable invoiced usage snapshots and ADR 0023 already decided what that
means: the invoice is frozen at issue, so the number on it comes from a row
that cannot change, and the row is stamped with the item that quoted it and
can never be quoted again. A meter that revises history writes a *second*
snapshot; the correction is a credit note.

**Zero is a line, not nothing.** A bandwidth line that disappears the month
somebody stayed inside their allowance reads as a billing mistake — and it is
the month they most want to see the figure.

**Rounded once at the line, never per unit.** A hundred and fifty gigabytes at
a third of a penny is either nothing or a pound if the rate is rounded first.

**The usage sweep runs daily for the month that has ended**, not monthly on
the first: a run that has to happen on a particular day loses a month
permanently the first time a worker is down for one. Every repeat is a skip
because of the unique key on `(meter, period_start, period_end)` — ADR 0031's
rule, enforced by the index rather than by a check somebody could forget.

**`OrganizationSubtree` is the one place that narrows a boundary-free sweep to
a seller's own customers.** A meter and a backup protection both belong to the
customer's organization while the sweep runs as the seller with no boundary at
all, and `withoutBoundary()` is the whole installation — which is exactly what
a reseller must not see. It was about to be written a second time, which is
the `ResolveSeller` rule again.

**A helper function in a Pest file is global, and two of them is a fatal.** A
test file has no namespace — the same fact behind "never `use` a global class
in one". `function reading()` existed in `AlertingTest` and was written again
in `UsageMeteringTest`; both files in one process is "Cannot redeclare", which
takes the whole suite down rather than one test, and a file written in
isolation passes. `tests/Feature/TestHelpersTest.php` refuses it now.

It was found the long way round: **Rector reported a three-argument call as
having extra parameters**, because it had resolved the *other* function of
that name. A static analyser disagreeing with code that plainly works is worth
reading twice before it is worked around.

**Phase G has begun** (`docs/architecture/phase-g-plan.md`), and the DCIM spine
is the first thing in it. **It is the first thing in this product that no
adapter can discover**: a rack is not an API, somebody types it in, and core
ships no rack sizes, no naming convention and no assumption that a datacenter
has more than one room. DCIM is core for the reason IPAM was — a module cannot
draw a screen, and this is almost entirely screens.

**A device in a rack is a `servers` row or a label, never a second table of
machines.** A `devices` table would immediately be a second answer to "what
servers do we have" (ADR 0043). What a rack position adds is *where it is*,
which is the fact no other table holds; the label covers the switch, the patch
panel and the blanking plate, none of which this platform sells.

**Units are numbered from the bottom and the elevation is drawn from the
top.** Both facts are on the screen, because an operator about to send a
technician needs to be sure which way round it is. A device occupying four
units is drawn once and continued three times — repeating its name reads as
four machines, which is the mistake a rack diagram exists to stop.

**The overlap rule cannot be an index.** A unique key on `(rack_id,
start_unit)` stops two things starting on one unit and says nothing about a 2U
device landing on the 1U above it, so the check is read-then-write under a lock
on the **rack** row — `AllocateAddress`'s shape, because the units being
claimed have no rows of their own to lock — and the index is the guard behind
it. A refusal names what is in the way and where: "the chassis is already in
units 10 to 13", never "that does not fit".

**Free units are the recessed ones, not the occupied ones.** Tinting what is
there makes a full rack look like a rack of holes. It also fixed a real
contrast failure: `danger-subtle` at 12px on `surface-secondary` is **4.49:1**
where 4.5 is the floor, and the Take out button sat on exactly that.

**`.row-actions` now works outside a table.** It was inert on a list — which
is a note already in this file, made again — because only `.data-table`
revealed it. `.hover-rows` is the second container rather than a second class:
one rule, two shapes, and the `(hover: none)` escape applies to both.

**A borrowed column label, for the fifth time.** The rack list headed its
"9 / 24" column with `dcim.rack.elevation` — the heading of another section
standing in for a word. Every count column needs a word of its own.

**`AdminActionRoutesTest` caught a link to a screen that does not exist.** The
rack elevation linked a server's name to `/admin/servers/{id}`, and there is no
per-server page in this product — servers are managed on Apps → Infrastructure.
A path a page names is a promise, and the guard written after the fleet screen
spent several phases posting to a 404 is what stopped this one shipping.

**Hardware inventory is about parts, not machines.** A disk outlives the
machine it was first fitted to, and "where has this serial been" is what a
warranty claim turns on — so `part_fittings` is append-only like
`ip_assignments`, a part is in one machine at a time, and fitting one that is
already somewhere closes that fitting in the same transaction. Fitting a part
into the machine it is already in does **nothing**: a double-press must not
read as somebody pulling the disk and putting it back.

**A warranty has three answers, not two.** In, out, and nobody recorded one.
The third is a gap in the register, and drawing it as expired would send
somebody to argue with a vendor who is still obliged. The list sorts soonest
to lapse first and the unrecorded ones **last**, because the screen is opened
for the things about to happen.

**`warranty_until` is not validated as being in the future.** A part whose
warranty ran out last year is exactly the one an operator needs to record.

**`FilterSelect` has no `any-label` prop** — the "any" choice is the first
option with an empty value. Passing one puts an attribute on a `<label>` and
leaves the filter showing nothing, which is what happened on the hardware
screen. `ComponentPropsTest` now names it alongside `AppTableRow`: the list is
the components where an ignored attribute is *invisible*, and this was the
second.

**A scripted edit with no assertion is an edit that may silently not happen.**
A batch of `str.replace` calls where only some had `assert count == 1` left an
old form on the page beside its replacement; `vue-tsc` caught it, and only
because the handler's signature had changed. Assert every replacement, or use
the Edit tool, which fails loudly.

**`Relation::Powers` has existed since Phase A and now has its first caller.**
The edge goes from the **outlet**, never from the PDU: two devices on one PDU
are usually on different breakers, and A-versus-B redundancy is the only
question anybody asks of a power diagram. `PowerFeed::Unknown` is never
guessed into `A` — a single-fed device that looked redundant is the one wrong
answer that costs an outage — and **which feed a PDU is, is configuration**,
because no PDU knows which of a rack's pair it is and an operator does.

**A temperature is a number and a leak is a state.** `SensorKind::isReading()`
decides which, and it is the rule `DiscoverPower` follows: readings become
telemetry, states become attributes on the node. A state stored as 1.0 is a
chart nobody can read and an alert nobody can word.

**Humidity has no `higherIsWorse`.** It is bad in both directions — dry air is
static, wet air is condensation — and a single boolean cannot say so, so it
falls to null with everything else whose badness is an operator's own rule.

**A PDU with no probe answers 404, and that is not a failure.** An adapter
that threw would make every sweep on every unprobed PDU look like an outage,
so a missing sensor collection answers with nothing. A missing *outlet* list
is still a throw: an empty one would retire a rack's whole power path.

**An outlet's label is the only place the device is recorded.** There is no
field for "what is plugged in" — an operator types the machine's name into the
socket's label, which is what every datacenter does. A default name
(`Outlet A2`) means nobody said, and is kept as nothing rather than as a
device called `Outlet A2`.

**A PDU's `state` is a word and its `power` may carry its unit.** `OnWait` is
neither on nor off and answers null; `1.4 kW` parsed as watts is a thousand
times wrong, which is a rack that looks empty.

**A private constant may shadow an imported class name.** `private const
string SensorKind` beside `use …\Power\SensorKind` is legal PHP that every
reader has to parse twice. Renamed.

**`pluck()` on a cast column returns the cast values.** A test asserting
`toContain('temperature')` against a column cast to `MetricKind` compares a
string with an enum and fails while the data is correct.

**Remote hands is a record before it is a request** (§11, `remote_hands_tasks`,
`/admin/infrastructure/remote-hands`). An audit row saying a machine was opened
is worth more than a ticket saying somebody was asked to open it, so every move
writes one and closing a task asks for the sentence the whole record exists for.
Four things it settled:

- **The technician is a name, not a staff user.** The person who walks to the
  rack works for the datacenter and has no account here; a foreign key would be
  asking for one to be invented.
- **Evidence is a reference, not a file.** A link to a ticket or a photograph
  somewhere else. Nothing is uploaded, which is one fewer thing to store,
  redact and back up.
- **There is no `Failed`.** A technician who went and could not do it has still
  been, and the outcome says what happened — a state would only say that
  somebody has to read it. A task that should not have been asked for is
  `Cancelled`.
- **`Scheduled` may go back to `Requested`.** A window that falls through is an
  ordinary Tuesday, and cancelling and re-raising would lose the thread.

**A status label is the wrong wording for the button that moves something into
it.** The row actions read `Agreed`, `Somebody is there`, `Called off` — three
buttons stating facts rather than offering actions, which is the "a button is
labelled with what it does" rule through a new door. `RemoteHandsState` has an
`actionKey()` beside its `labelKey()` now (`Agree a window`, `Somebody is there
now`, `Call it off`), and an enum whose members will ever appear on a button
wants both from the start. Found by hovering a row; nothing in 2270 tests could
see it.

**A refusal is a sentence somebody reads, so it is a key.** `RackRefused` shipped
in the DCIM spine with its wording written into the exception, and
`DcimController` put `getMessage()` on the form — hard-coded English at an
operator working in Turkish, which is exactly the `LicenceCheck` trap. Both
refusals carry `key()` and `replacements()` now and the controller renders them.
The English message stays on the exception, because that is what a log wants.

**`items-end` on a row of fields aligns the wrappers, not the controls.** One
field with a hint and two without put three inputs at three different heights.
A grid is the answer rather than a flex row: fields that belong together
(the two serials) share a row, and a field that does not stands alone.

**A form is a column, not the window.** The raise form ran the full 1400px, so
the sentence somebody types for a technician was one line 1390 pixels wide.
`max-w-3xl`, like every other form in the admin area.

**The WordPress fleet is a read, and the write half is deliberately absent**
(§18, `SiteProvider`, `DiscoverSites`, `modules/infracms/sites-wptoolkit`). A
site is a graph node and its plugin list is an attribute on it — §14's rule
again, because four hundred sites times sixty plugins rewritten every day is a
time-series database nobody sized. A panel answers, never the site itself: a
WordPress installation cannot say what it is running without a plugin inside
it, and a platform that needed one could not answer "which of our sites has
the vulnerable component".

**Out of date and vulnerable are different facts, and two alert subjects
rather than one.** Three releases behind with nothing said against it is
housekeeping somebody does on a Thursday; a published advisory is tonight. A
single "needs attention" figure would let four hundred of the first kind hide
the one of the second — and an operator who learned to ignore that list would
be right to.

**A site nothing looked at is not a site that is clean.** `vulnerable` is
three-valued on a component and `vulnerability_data` on the node says whether
anything checked at all; `AlertSubject::SiteVulnerability` produces no
observation where nothing did. Reading an absent advisory list as zero hands a
whole fleet a clean bill of health it never earned, which is the one number in
this phase somebody would put on a slide.

**Not knowing a latest version is neither current nor behind.**
`SiteVersion::isBehind()` answers false to both nulls, and one place answers
it: two copies would eventually disagree, and a site would be counted as up to
date in the figure and out of date in the list.

**`AdapterArea` is now 25, not the handoff's 23.** `Mail` was added for §13 and
`Site` for §18, each because the requested feature had no contract in §27's
list and the nearest one was not near — filing a customer's plugin list under
`Automation` would put Ansible and WordPress behind one switch an operator
flips once.

**There is no `site.update.write`, and that is the opposite of
`FirewallPolicyWrite`.** The firewall's write exists with no interface method
because it is a write somebody could make today over the same connection, and
naming it keeps the audit honest. A site update capability would have nothing
in core that could ever call it, which is a switch that lies — the rule
`ReputationRead` states from the other side.

**A page that holds a second copy of an enum's shape will drift from it.** The
alert rule form decided which subjects are numeric with a hand-written
`subject === 'metric' || subject === 'capacity'`, and three phases later
certificate expiry, blocklist age and backup age were all drawn with no
threshold field — while `EvaluateAlertRule` refuses a numeric rule with no
threshold. **Every rule an operator wrote on those three subjects was a rule
that could never fire**, and nothing said so: the screen rendered, the rule
saved, the list stayed empty. `needsTarget` and `numeric` are sent per subject
now and `AlertingTest` pins them against `cases()`. Anything a form decides
from an enum's *meaning* — which fields to show, which are required — belongs
in the payload, not in a literal in the template.

A hint that names two of six cases has drifted the same way. "A percentage for
a ratio, a number of days for a capacity forecast" was written when there were
two numeric subjects; it is a sentence about any of them now.

**Phase H has begun** (`docs/architecture/phase-h-plan.md`). The reconciliation
engine, remediation proposals, orphan detection, revenue leakage, cost entries
and profitability are in; customer health and noisy neighbour are not. The automation builder and the AI
assistant are deliberately outside the phase.

**It needed no new contract.** `ProvisioningModule::sync()` and `SyncResult`
have existed since Phase 6 and nothing had ever called them on a schedule —
`SyncResult`'s own docblock says "a sync reports; it does not decide", which is
exactly what a reconciliation engine wants. Adding a `ReconciliationProvider`
would have been a second way to ask the same question. Before writing a
contract for a new phase, grep for one that was written for the old one and
never used.

**`RunServiceOperation::observe()` is `sync()` without the parts a sweep must
not do.** It writes no service event, because an event per service per hour
would bury the ones an operator reads; it does not throw, because an
unreachable provider is a conclusion rather than a failure of the sweep; and it
returns the `SyncResult` itself, because `sync()` drops the remote status,
which is the whole point.

**`unknown` is a class of its own and must never fold into `drift`.** A machine
that might have been resized and a machine nobody could ask are different
things to act on, and the second is a monitoring problem. Folding them would
put a panel's outage in front of an operator as four hundred customers whose
accounts had apparently changed — with the one real finding somewhere in it.
An adapter that is reachable and has no opinion (`ManualModule`) is `unknown`
too: reading its silence as agreement would mark every manually-provisioned
service healthy for ever.

**A finding worsens in place.** A drift that became a missing account is the
same finding getting worse, so the class is refreshed on the existing row —
closing and reopening it would reset the clock that says how long it has been
wrong.

**A dismissal is keyed the way a finding is keyed, not by a finding's id.** The
whole point is to survive tonight's sweep clearing that row and raising an
identical one. It has an author, a reason and usually a date it stops applying,
and whether it still applies is asked when the sweep runs rather than stored —
ADR 0031 applied to somebody's judgement, so the finding comes back by itself
with no scheduled task that *has* to run.

**A dismissal suppresses the row, never the comparison.** The sweep still asks
and still clears what has been fixed; what changes is whether anybody is shown
it. Skipping the comparison would mean a machine somebody deliberately built by
hand could then change under them and nothing would say so.

**I wrote a translated sentence into a column again, and the browser found
it.** `expected` and `found` were being stored as `__($status->labelKey())`, so
an hourly sweep would have frozen "Active" and "Suspended" into whichever
locale the scheduler ran in — on a Turkish installation, English for ever. The
row holds values now and the screen words them, **except** a provider's own
message, which is evidence rather than vocabulary and is stored and shown
verbatim. Both halves are pinned by tests. The rule is old; what is new is that
it applies to a *stored enum value's label* and not only to a sentence.

**A form that appears at the top of a list has to name the row it is about.**
The dismiss form said "Stop raising this?" with four rows underneath it and no
indication which. Same fix as the remote-hands move form, found the same way.

**"Show what has been put right too" was only half true.** A closed finding is
either fixed or set aside, and both live behind one filter — so the filter says
"closed" and the row's own line says which kind. A filter whose word covers one
of the two cases is a filter that lies about the other.

**Remediation is a record somebody approves** (`remediation_proposals`,
`RemediationAction`, `ProposalState`, `Remediations`). The platform writes a
suggestion beside every open finding; a person agrees, turns it down, or
chooses something else; and only then does anything happen. Five decisions
hold it up:

- **The suggestion is always the action that changes the least.** Where a
  finding could be answered by correcting the record here or by changing the
  customer's account there, the platform proposes the first — the provider is
  treated as right, which is usually what happened. The remote action is
  offered beside it and never pre-selected, because "the platform suggested
  it" is a sentence that ends up in a post-mortem.
- **Termination is offered and never suggested.** `RemoveService` has no
  opposite and destroys somebody's data on somebody else's machine.
- **`RemediationAction` has no `Fix` member.** An action named after its
  outcome rather than its direction is how somebody approves a thing that
  terminates a hosting account believing they have corrected a spreadsheet.
  Every member says which way it points, and `isRemote()` is the line the
  confirmation's wording and its level are drawn on.
- **A proposal is stale when the finding moves underneath it**, and `Stale` is
  a state rather than a failure. This is `ApplyNetworkChange`'s fingerprint
  check for a comparison: a proposal written at four o'clock about an account
  the panel has since restored would, applied, terminate a live account. The
  second time this product has needed the idea, which makes it a pattern.
- **The sweep never overwrites a proposal an operator chose.**
  `proposed_by` is what says so, and a sweep that put its own suggestion back
  every hour would be one nobody could work with.

**`RunServiceOperation` returns a failed result rather than throwing**, so a
caller that only catches exceptions records every refused suspension as
applied. `Remediations` checks the outcome. `AlreadyDone` stays a success, for
the reason ADR 0026 gives — and it is exactly right here, because the whole
finding is that two sides disagree and an adapter saying the account is
already in that state is agreement.

**A reason the confirmation collects and the endpoint discards is a sentence
nobody reads** — found again, on the apply dialog. `AppConfirm` emits the
reason at its two higher levels; the endpoint takes it, the row keeps it and
the audit record carries it.

**Strict mode caught a lazy load that a one-row queue would have hidden.** The
proposal's `decider` was read per row, and the screen rendered perfectly until
there were two findings on it. `Builder::hydrate()` is the heuristic, and this
is the third time it has been the difference between a green test and a 500 on
a real screen: eager-load a relation the presenter touches, even when the test
fixture has one of everything.

**A row of controls revealed on hover reflows the whole table.** Four actions
appeared when the mouse crossed a row, every other column narrowed, and a
service name re-wrapped under the cursor. The cell reserves its width now, and
the rarest action — saying a difference is deliberate — moved into the menu
where it costs none.

**A select repeating the value the cell beside it already names is two places
for one fact**, and on every row it is also a label ("Something else") printed
four times. `AppMenu` in the row actions is what this product uses for a
choice that is not the ordinary one.

**Orphan detection is reconciliation read from the other end** (`DetectOrphans`).
`ReconcileServices` starts with a row and asks the provider about it; this
starts with what the providers reported and asks whether anything here owns
it. A machine built by hand for a migration and never recorded is invisible to
the first and is exactly what the second is for. Four rules:

- **Absence of an adapter is not evidence.** Only what has actually been
  discovered is considered, so a platform with no hypervisor module reports no
  orphaned machines rather than reporting that every machine is orphaned. The
  same rule a stale metric and an unread advisory live under.
- **The row says what the match was made on.** A machine is matched to a
  service by `external_id`, which is an identity; a site is matched to a domain
  by name, which is a weaker claim. An operator deciding whether to destroy
  something deserves to know how sure the platform is.
- **An address is matched on bytes, never on text**, and **the mask is
  stripped first**: a device reports `192.0.2.1/24`, `inet_pton` answers false
  for that, and a silent false would drop every address on the network and
  report no orphans at all — the quietest possible way for this to be wrong.
- **Removal is not offered where there is no row to act through.** An orphan
  found this way has no subject — that is what makes it an orphan — so this
  platform cannot reach the machine, and a button that was offered and always
  refused would be worse than no button.

**Orphans are their own `source` in `reconciliation_findings`.** One source
per question, because `clearDeparted` closes everything a source did not
report this time: sharing a source with the service sweep would mean a
hypervisor that could not be reached cleared every orphaned machine found last
night.

**A dash where a fact is absent reads as missing data.** An orphan's "We say"
column was an em dash, and nothing here owning it is the whole of what an
orphan *is* — it says "No record here" now. The same reading applies to the
`matched_on` the server had been sending and the screen ignored: a fact the
server bothered to send is a fact somebody meant to show, and here it is the
one an operator wants most before destroying anything.

**Revenue leakage is four joins over rows this platform already owns** (§21,
`DetectLeakage`, `leakage_findings`). No adapter, no provider, no model —
which makes it the one family in Phase H whose answers do not depend on an
untested parse. A reconciliation finding is only as true as an adapter; these
are arithmetic.

- **`renewal_invoiced_through` is the question, not `next_due_on`.** The
  renewal sweep and the importer both write it, and it is the column that says
  how far billing actually got. A service whose due date has passed is
  ordinary for a few hours a night.
- **A line raised before the period began is last month's invoice.** Matching
  on `invoice_items.subject_type`/`subject_id` without a date would find last
  year's line and hide the leak entirely — and matching on the *description*
  would be worse, because a line copies its words (ADR 0021).
- **An addon on a service nothing is invoicing is the service's finding.**
  Reporting both counts one problem twice and doubles the figure on the strip.
- **Zero means free, which is a price somebody set.** A service with no
  recurring amount is skipped rather than reported.
- **A payment taken this morning is a bookkeeper's afternoon, not a leak.**
  Two days, read from the **ledger** rather than from `payments`, because the
  ledger is the truth about money that moved (ADR 0024).
- **Nothing raises an invoice.** Charging a customer nobody decided to charge
  is the one thing this family must not be able to do, and a test says so.

**A finding is never an accusation.** A charity given free hosting, a domain
held for a customer arriving in March, a payment taken on account — all three
appear here and all three are deliberate. That is why the only write on the
screen is a dismissal.

**One dismissal table for every family of finding.** `reconciliation_dismissals`
became `finding_dismissals`: the act is identical whether the finding is a
machine the provider has and we do not or a service nothing is billing for, and
`source` was already the discriminator. A second table would be a second answer
to one question, and the second one is always the one that misses the next
feature.

**A strip that is not a legend for the table under it is a strip nobody reads
twice.** The figures were ordered by size and the rows alphabetically by
currency code, so the strip said TRY, EUR and the table led with EUR. The table
takes its currency order from the strip now.

**A label that repeats its own value says nothing.** `TRY` above `TRY 450.00`
is the currency twice; the cell carries how many findings make up the figure
instead, which is the fact it was missing. And that count is worded with an
**adjective** (`:count open`), because `useTranslations()` has no
`trans_choice` — a `|` choice string in the browser renders its own pipe, and
"1 findings" is the other half of that trap.

**The figure is what would have been invoiced, not what is owed**, and the
sentence under the strip says so. Nobody has been invoiced, so nothing is owed
— and this table must never be mistaken for a ledger.

**Cost entries are rows an operator types** (`cost_entries`, §21). No adapter
reports a Hetzner invoice or the rent, so this is the same shape tax rules and
dunning steps have, and core ships none of them either.

**This is not the ledger and must never be mistaken for it.** The ledger is
append-only because a financial history that can be rewritten is not a history
(ADR 0024); a cost entry is a *statement* and is edited when the price changes.
A negative amount is refused — a cost that is income is a credit note, and
letting one in here would be a second way to move money.

**Core ships no allocation.** A server costs €200 and carries forty accounts;
how that lands on the forty is somebody's commercial judgement. Two strategies
to begin with and a third when somebody asks: `Even`, which makes no claim and
is wrong in a way everybody understands, and `Weighted`, which makes a claim
the graph can support.

- **Whichever produced a figure is written on the figure**, and where an entry
  asked for weighted and nothing was measured the share says `even` — a row
  claiming a weighting that did not happen is the lie the whole class is
  arranged against.
- **An unmeasured service is assumed to be average, never free.** Zero would
  make the unmonitored box carry no cost at all and look like the most
  profitable thing on the estate. `ScorePlacement` settled this once; the same
  reasoning applies to money.
- **A stale reading is not a service using nothing**, so it is left out and
  falls to the average rather than to zero.

**A cost that reached no service is kept, not dropped.** A server bought last
week with nothing on it costs exactly as much as a full one, and a report that
quietly dropped it would understate the month — the one direction a cost report
must never be wrong in. `unallocated()` is where those land.

**A one-off is charged in its own month and no other.** A migration paid for
once in March is not a twelfth of anything: spreading it makes eleven months
look worse than they were and March look better. Same rule `MonthlyRecurring`
follows when it skips a one-time line rather than counting it as zero.

**`starts_on` and `ends_on` bound a cost in time.** A server bought in June did
not cost anything in May. Without them the first month a provider ran this
report would charge every historical month with today's estate.

**A presenter that touches a relation eager-loads it, and a one-row fixture
will not tell you.** The costs screen read `subject` per row and 500'd on the
first page with four entries — the second time this exact shape reached a
browser in one phase, after the reconciliation queue's `decider`. Strict mode
reports a lazy load only above one row (`Builder::hydrate()`), so the rule has
to be applied by reading the presenter, not by waiting for a test.

**And the metric printed its own key again.** `MetricNames` was written in
Phase F for exactly this and the new screen still sent `MetricKind->value` to
be rendered raw. A wording that exists and is not read is the same bug as a
setting that is stored and read by nothing — grep for the reader when you send
an enum value to a screen.

**A pest run that is killed mid-way still prints its summary line.** Two
background runs were stopped by the harness for host memory pressure and each
reported hundreds of failures with a clean `Tests: 404 failed, 1931 passed`
footer — which reads exactly like a real breakage and is not one. The whole
suite, run afterwards in three foreground chunks, passed 2373 with nothing
failing. Before chasing a mass failure, check whether the run was killed: a
genuine break shows the same failure shape in a targeted run, and this one
never did.

**A margin is not always a number, and the profitability report says so.**
There is no rate anywhere in this product, so a customer earning euros on a
server costing lira has a revenue, a cost and **no margin** — printing the
revenue as though the cost were zero would be the most misleading figure this
product could produce. The rule is narrow and checkable: a margin is stated
when **every currency that has a cost also has revenue**. A currency with
revenue and no cost is fine; it means nobody recorded a cost against it.

The same data answers two ways, which is the point: grouped by customer it is
not comparable, because a customer earning only euros carries a share of a
lira cost; grouped by server it *is*, because the server earns both. Neither
answer is a compromise.

**Revenue comes from the services, never from invoice lines** — a line copies
its description (ADR 0021), so grouping by one merges two products renamed the
same thing. MRR is integer division by the cycle's own length and a one-time
price is skipped rather than counted as zero.

**It is a snapshot, not a history, and there is deliberately no month-by-month
chart.** A cost entry is a statement somebody edits when the price changes,
not an append-only record, so a trend line would redraw itself every time a
typo was fixed — and a trend that changes when you correct a figure is a trend
nobody can use.

**A customer is an organization of its own, so a service belongs to the
customer's organization and never to the seller's.** Three classes written in
this phase narrowed through `whereHas('customer', … organization_id = seller)`
and therefore matched **nothing at all on a real installation** — the leakage
sweep would have reported no leaks for ever. Every test passed, because the
fixtures forced the customer into the provider's own organization, which
`CustomerFactory` goes out of its way *not* to do: its docblock says in as
many words that "a customer sharing an organization with its reseller would
not be a separate customer at all". `OrganizationSubtree` is the one place
that answers "whose customers are these", and it is what these sweeps use now.

The fixtures stopped overriding `organization_id`, and the guard was checked
against the bug before being trusted: reverting the filter fails the test.
**A factory that builds a realistic shape is worth more than one that builds a
convenient one** — an override in a `beforeEach` is the cheapest way to make a
whole family of tests agree with a bug.

**`toLocaleDateString()` with no locale follows the operating system, not the
reader.** Invisible on `24.09.2026` and glaring on a month name: an English
page headed "Eylül 2026". The shared `locale` prop is what a date is worded
with when the wording has words in it.

**`MetricStrip`'s named slot is for exactly this.** A cell per currency
printed the word "Cost" twice with nothing to tell the two apart; two cells,
each stacking its own currencies, is what the component was built for — and
its docblock says so.

**Essential information never lives in a tooltip.** Why a margin is missing is
the most important thing on that row, and it was a `title` attribute. It is a
sentence under the table now, shown only when something actually is not
comparable.

**Customer health is signals, and there is deliberately no score.** §21 asks
for it to be explainable, and the only honest way to be explainable is not to
compute the thing that would need explaining: a number between 0 and 100 is a
number somebody acts on and nobody can reproduce, and the weights that built
it are a commercial opinion this platform has no standing to hold. Each signal
carries its own arithmetic, the rows are ordered by the **worst thing that is
true** of each customer — an ordering the product already has words for — and
the screen says so in a sentence rather than leaving a reader to wonder where
the total went. `CustomerHealthTest` is what keeps it that way: it walks the
row's own keys and refuses one named anything like `score`, `rating` or
`total`.

**A signal with nothing to say is absent.** A wall of zeroes is a wall
somebody stops reading, and the one figure that is not zero disappears into
it — so a customer with nothing against them is not listed at all, and the
meta line says how many were examined so the absence means something.

**Seven questions asked once for the page, not seven per row.**
`CustomerHealth::across()` is the implementation and `for()` delegates to it.
A `count()` inside a loop is not a lazy load, so strict mode says nothing and
nothing else would have either — the test is the only thing that can.

**Every sentence with a number in it is built on the server.**
`useTranslations()` has no `trans_choice`, so a count worded in the browser
reads as "1 invoices" — found on the rendered screen, for the third time in
this product. Wording it in the controller also deletes the `switch` on the
signal kind that the page was carrying, which was a second copy of a mapping
the enum already owns. Turkish needs no plural on a counted noun and
`trans_choice` returns a string with no `|` in it unchanged, so one call is
right for both languages.

**Noisy neighbour is a comparison, not a threshold, and Phase H is complete**
(`docs/architecture/phase-h-result.md`). 40% of a machine's CPU is fine on a
box with two services and a problem on one with forty, so there is no number
in `NoisyNeighbours` that says "too much": every reading is measured against
the median of the services on its own machine, and the median is printed
beside it so the claim can be checked rather than believed.

**The median, never the mean, and that is the whole design.** The mean of
thirty-nine idle accounts and one runaway is a mean the runaway moved — it
rises with the thing it is supposed to measure against, and with two runaways
it rises far enough to hide both. The median does not move at all, and the
test that pins it builds exactly that fleet.

**It declines more often than it answers**, like `CapacityForecast`. Fewer
than four services on a machine is not a distribution: with two, the median is
the midpoint between them, so one of the two is always "twice the median" —
arithmetic wearing a finding's clothes. And where only per-node metrics exist
the answer is **"nothing can be said", in words**. That makes three different
empty answers — nothing is noisy, nothing reports per service, nothing carries
enough neighbours — and `NeighbourReport` carries the counts so the screen can
keep them apart. One empty state for all three would tell an operator
everything is fine when the truth is that nothing was measured.

**A median of nought is the common shape, not a divide-by-zero.** Thirty-nine
idle sites and one busy one is what this feature was built for and is exactly
what produces a zero median, so the row is reported with **no multiple** —
"every other service uses none" — rather than with an invented infinity. There
is deliberately no floor under which a reading is called insignificant: 0.1%
of a 128-core machine is not nothing, and core has no way to know what is.

**`MetricKind::isContended()` is a different question from
`higherIsWorse()`.** Disk *latency* is worse when higher and is what the
**victims** of a noisy neighbour suffer — comparing it across neighbours names
the wrong service. Disk *IOPS* is what the noisy one is doing. It is
conservative with `default => false` for the reason the alias table is: a
metric core does not recognise as shared is left out rather than guessed in,
and the cost of leaving one out is a question nobody asks, while the cost of
guessing one in is a service named for something it is not doing to anybody.

**A control that cannot change the screen is absent, not disabled.** The
multiple is a filter over a list, so where nothing could be compared there is
no list for it to narrow and the filter is not drawn at all.

**`resources/js/metrics.ts` is the one place a reading is written down**, for
the reason `status.ts` is one place: the Telemetry screen and this one print
the same numbers about the same nodes, and two screens that format them
differently are two screens an operator has to reconcile. The formatter was
copied out of `Telemetry.vue` the moment a second caller existed.

**A reading with no unit is a number nobody can read.** "412" under a heading
saying "This service" was the first browser finding; `unitLabel` comes from
the server like everywhere else, and the screen prints "412 /s".

**A page's description and the note under its table were explaining the same
thing.** The intro said "measured against the median of its own neighbours"
and the `AppAlert` underneath said it again at more length. The description
says what the screen *is*; the note carries the part that is not obvious.
Worth reading a new screen's two longest sentences next to each other before
calling it done.

Two Python traps, both of which aborted a scripted edit before it wrote
anything: `\N` in a non-raw string (`App\Http\...\NoisyNeighboursController`)
is a named-unicode escape and a syntax error, and so is a lone `\u`. A PHP
namespace in a Python string wants `r"..."`.

**A whole float is an integer once it has been through JSON.** `5.0` reaches
the browser as `5`, which is why the multiple's select options are strings
built with `(string)` on both sides — and why an `assertInertia` comparing
against `5.0` fails against a response that is plainly correct.

**Phase I has begun** (`docs/architecture/phase-i-plan.md`, ADR 0049), and it
**builds no application**. ADR 0044 already put mobile in a separate
repository consuming `/api/v1`, so what this phase owes is the four things
that ADR's consequences say the apps cannot exist without — a staff API
surface, a token lifetime, refresh rotation, and a device inventory with
remote revocation. Every one is a decision about this product's security
rather than about a phone.

**A staff API token is always scoped, always expires, and cannot do the
dangerous things** (ADR 0049). The reason it is not one line of middleware is
a fact this product has hit twice: **an Administrator holds every staff
permission by design** — which is why `resellers.administer` is a gate and
the tax screen is owner-only — so a staff token issued the way a client token
is issued would be a bearer string that is root on the installation, never
expires, and lives in a pocket. There is no `*`, a token carrying no scope is
refused outright, and one with no expiry is refused too: nothing here can
issue a staff token without one, so a token that has none came from somewhere
else.

**What is absent from the staff API is the policy.** §26 says some actions
are web-only, and a client can be rewritten — so leaving a button out of an
app enforces nothing and the refusal has to be **the absence of the
endpoint**. No firewall apply, no power action, no termination, no restore,
no drain, no bulk route. `StaffApiSurfaceTest` walks the routes and fails if
one appears, and it is a list of **forbidden words rather than an allow-list**:
an allow-list needing an edit for every ordinary read gets edited without
thought, and the one edit that mattered goes through with the rest.

**`StaffApiScope` is a separate enum from `ApiScope`**, not an extension.
They narrow different things — what a customer shares about their own
account, against what an operator can do to everybody's — and `tickets:read`
exists in both meaning two different things, which a test asserts.
`ScopeVocabulary` is the one place a guard maps to its list, so the request
that validates a scope and the controller that filters one cannot read
different enums.

**Rotation without reuse detection is theatre**, and the first version of it
did nothing at all. The revocation was written **inside the transaction the
refusal then rolled back**, so a detected reuse revoked nothing and the thief
kept working — with every other test in the file passing. The device is
carried on the exception now and revoked outside the transaction. Anything
that must survive a throw cannot be written in the transaction that throw
aborts.

**Every staff endpoint was published in `openapi.json` with no security at
all**, because the generator only knew `RequireApiScope`. The document is the
contract an integrator writes against, and saying an endpoint is
unauthenticated when it is not is the worst thing a generated one can say.
When adding a second middleware of an existing kind, grep for what reads the
first.

**`VocabularyTest` caught a new enum's `labelKey()` with nothing behind it**
before anything rendered it — the designed symptom working, and the guard
getting there first. A dotted key would have been the permission-slug trap
again, so `StaffApiScope` uses underscores like `ApiScope` does.

**The admin incident screen printed its refusals in English at a Turkish
operator.** `IncidentRefused` carried only a message and both call sites put
it straight into a form error. It has `key()` and `worded()` now, in both
languages. The rule `RackRefused` cost, found because a second caller was
about to make the same mistake — which is the usual way these surface.

**`Guard::fromApiRouteName()` is separate from `fromRouteName()`** because the
browser areas and the API do not share a naming convention and must not be
made to: the staff browser area is `admin.*` and its API surface is
`api.v1.staff.*`. A staff route name resolved to the *client* guard silently,
and the symptom was the staff sign-in endpoint refusing every staff scope as
invalid.

**A `\N` or a lone `\u` in a non-raw Python string is a syntax error**, so a
scripted edit containing a PHP namespace aborts before writing anything —
three times in one session. The repo's own rule is the answer: anything with
a backslash goes through Write or Edit.
