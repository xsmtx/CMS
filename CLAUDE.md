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
plan before any of it was built — and its phases are lettered. **Phase A is
complete** (`phase-a-result.md`); B to J are not started. Do not begin one without
being asked for it.

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
