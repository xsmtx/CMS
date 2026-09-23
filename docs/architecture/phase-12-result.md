# Phase 12 — Module SDK Result

Status: complete
Date: 2026-09-23
Plan: `phase-12-plan.md`
Next phase: none scheduled — the roadmap's phases are done

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **1085 passed, 4337 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check resources` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 29 passed |
| Build | `vite build` | pass |
| Migrations | applied on MariaDB 11.8 | clean |

Phase 11 finished at 959 tests. This phase ends at 1085 — the module
lifecycle accounts for 24 of those, and the rest came with the WHMCS-shaped
operator screens delivered alongside it (Transactions, Add Transaction, Open
New Ticket, Add New Order, the support overview, the cancellation queue and
the todo list).

## 2. The decisions this phase turns on

**A module may execute, and enabling is the moment it does**
([ADR 0038](../adr/0038-a-module-may-execute.md)).

Phase 11 drew a hard line: a theme may not execute. A module exists
precisely to run code, so refusing execution here would refuse the feature.
The question was never *whether* somebody else's code runs — it is **when**,
**on whose say-so**, and **what it can reach**.

The plan for this phase had `InstallModule` run the module's migrations and
sync its permissions, and called that "not consent yet". Writing it showed
that to be false. A migration is a PHP class the module author wrote;
running one *is* running the package. Consent cannot be split across two
steps where the earlier one already executes the code. So installing is now
exactly: read the manifest, check it fits, write a row.

**The SDK is platform contracts, versioned apart from the platform**
([ADR 0039](../adr/0039-the-sdk-is-platform-contracts.md)).

Everything on the `Module` interface is a contract in `app/Domain`. No
method takes or returns an Eloquent model, a facade or a framework class,
and an architecture test enforces that `app/Domain` has no framework
imports at all. `Sdk::VERSION` moves independently of the product: adding a
method with a default in `BaseModule` is a minor bump, changing anything a
module implements is a major one, and a major bump makes every existing
module refuse until its author has looked.

`app/Domain` is now public API. That is a real constraint on core and it is
meant to be felt.

## 3. What was built

**The SDK.** `app/Domain/Modules` — `Module` and `ModuleLogger` contracts,
`BaseModule`, `ModuleManifest`, `ModuleContext`, `ModuleType`,
`ExtensionPoint`, `ConfigField`, `NavigationItem`, `Widget`, `Registration`,
`Sdk`. `BaseModule` is the one class in `app/Domain` that is not `final`,
and the architecture test names it as the exception: it exists to be
subclassed by code this repository does not contain.

**The lifecycle.** `app/Application/Modules` — `InstallModule`,
`EnableModule`, `DisableModule`, `UpgradeModule`, `UninstallModule`,
`InspectModule`, `SaveModuleConfig`.

**Reading and loading, kept apart.** `ModuleCatalogue` reads JSON and does
nothing else. `ModuleLoader` is the only place a module's PHP enters the
process. `ActiveModules` is the runtime every registry asks "and what do the
modules add".

**The screens.** `/admin/apps` (Apps & Integrations, super admin only),
`/admin/apps/modules`, a generated settings form per module, and
`/admin/apps/connect` — passwordless access to a server through its stored
credentials, which never leave the server row.

**The commands.** `module:make` scaffolds a package that compiles;
`module:list` shows what is on disk beside what the installation has decided
about it. Both read only.

**The worked example.** `modules/example/status-board`, driven end to end by
`tests/Feature/ExampleModuleTest.php`. Nothing in core references it, which
is the point: if the SDK ever stops working from outside core, that file
fails.

**The guide.** `docs/modules/README.md`.

## 4. What the example found

A worked example earns its keep by being the first real consumer. This one
found three things no fixture had.

**A module with a required setting could never be enabled.** The settings
form was read from `Module::configSchema()`, which only a *running* module
can answer — and a module cannot run until it is configured. Deadlock, and
the first module with a required field hit it. The manifest now declares the
config too, which is the consistent answer: "what do I need to tell this
package" is exactly a question from before the package is trusted. A running
module's own schema still wins, for options only it can compute.

**Inspection before `boot()` records a module that provides nothing.** A
module works out what it provides from its configuration — this one
registers no health check until it knows what to watch — so the registration
written on the row was empty. It is now inspected **twice**: before `boot()`,
so a module claiming the wrong type is refused before it does anything, and
again afterwards, because that is the answer that matters. Asking twice also
closes the hole the type check exists to close, since a package that
answered honestly before boot and registered a gateway after it is exactly
the "quietly becomes something else" the type refuses.

**Composer's autoloader caches misses.** Anything that asked for a module's
class name before the module was enabled — a worker that had already seen
the row, a static analyser, a test scanning the tree — leaves it in
`missingClasses`, and no amount of `addPsr4` afterwards rescues it. The
symptom in the suite was a module that loaded perfectly alone and could not
be found when the architecture tests ran first. Modules now get their own
autoloader, prepended, bounded to each module's `src`. A module that could
be permanently unloadable because something looked at it too early is a
module that fails on the one box where it matters.

## 5. Also in this phase

The operator screens the handoff's admin map called for, and which the
earlier phases had left as rows in a menu: Transactions with an amount
in/out chart and an Add Transaction form, Open New Ticket with CC
recipients and a Markdown editor, Add New Order taken over the phone,
Support Overview, the cancellation queue, the module queue, the todo list,
and the WHMCS menu structure across the top of the panel with a fixed
footer.

Four bugs in previously untested screens were found by writing their first
feature tests: `TicketPolicy` and `OrderPolicy` had no `create()` method, so
`authorize('create', ...)` denied everybody including the owner; `store()`
read a `nullable` validated key directly, which is a 500 when the field was
not submitted; a validation rule named a table that does not exist. The
lesson is recorded in `CLAUDE.md`: if a screen has no feature test that
renders it, it has not been tested.

Permissions on the roles screen are now named. Eighty-four labels and
descriptions in both languages, read by `PermissionNames`, with a test that
fails if a new permission ships without wording in either language. The
lookup goes through one array indexed by slug rather than `__()` — a slug
has dots in it, so `__('access.permissions.crm.customers.view.label')` asks
the translator to walk five levels of nesting and quietly returns the key
instead.

## 6. Carried risks

Unchanged from Phase 11, and both still true:

**The provider adapters have never talked to their providers.** Stripe,
cPanel and Namecheap are implemented against their documented APIs and
tested against fakes. The first real credential is the first real test.

**The client area has never been driven in a browser.** Signing in requires
typing a password, which is not something this process does. Every client
screen has a feature test that renders it; none has been clicked.

**No module has been written by anybody else.** `status-board` is the only
consumer of the SDK, and it was written by the same process that wrote the
SDK. The version rule in ADR 0039 exists because that will stop being true.
