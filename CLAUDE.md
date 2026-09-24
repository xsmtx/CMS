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
