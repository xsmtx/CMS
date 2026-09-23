# Phase 12 — Module SDK Plan

Status: approved for implementation
Date: 2026-09-23
Scope: V2 roadmap Phase 12 — stable extension contracts, the module
lifecycle, module-declared permissions and navigation, compatibility
checks, module health and isolated logs, a scaffolding command, and one
example module that proves the SDK from outside core.

---

## 1. Starting point

Most of this phase's seams already exist. Every previous phase that met
somebody else's system put a contract in `app/Domain/<Context>/Contracts`
and a registry in `app/Infrastructure`, and a service provider filled the
registry from configuration:

| Contract | Registry | Since |
| --- | --- | --- |
| `PaymentGateway` | `GatewayRegistry` | Phase 4 |
| `ProvisioningModule` | `ModuleRegistry` | Phase 6 |
| `DomainRegistrar` | `RegistrarRegistry` | Phase 7 |
| `DeliversNotifications` | `ChannelRegistry` | Phase 8 |
| `AutomationRun`, `HealthCheck` | task and health registries | Phase 9 |
| `RiskEvaluator`, `TaxCalculator` | bound implementations | Phase 3 |
| `Entitlements` | bound implementation | Phase 11 |

So Phase 12 is **not** inventing an extension surface. It is answering the
question those registries have been deferring: *who else may put something
in them, and how does an operator install that without trusting us to have
read it.*

`modules/` exists as an empty reserved directory from Phase 0. The admin
map has an Extensions section the handoff specifies and nothing has ever
filled.

## 2. A naming collision, settled in advance

Phase 6 called a provisioning adapter a **provisioning module**, because
that is what hosting operators call it and what WHMCS calls it. Phase 12
calls a package a **module**, because that is what the handoff and the
admin sidebar call it.

Renaming either would be churn across live code and a database column
(`services.module`), so both names stay and the rule is written down
instead:

- **Module**, unqualified, means the package: `App\Domain\Modules`,
  `modules/<vendor>/<slug>/` on disk, the thing an operator installs.
- A Phase 6 adapter is always called a **provisioning module** in full,
  and lives in `App\Domain\Provisioning`.

A module may *contain* a provisioning module. That is the normal case.

## 3. The central decision: a module may execute, and that is why it is installed differently

[ADR 0037](../adr/0037-a-theme-is-a-package-and-may-not-execute.md) says a
theme may not contain PHP that runs. A module is the other half of that
sentence: a module is **code**, and the reason to keep themes inert was so
that modules could be the one place an operator makes a real trust
decision.

That decision shapes the whole lifecycle:

1. **Nothing on disk runs because it is on disk.** Discovery reads
   `module.json` — JSON, never a PHP file that returns an array. A module's
   PHP is loaded only when its row says `enabled`.

2. **Enabling is an explicit, audited act**, and the operator is shown what
   they are agreeing to first: the permissions the module declares, the
   contracts it registers into, the routes it mounts and the migrations it
   will run. "Install this free module" is the attack; showing the bill
   before the signature is the defence.

3. **A module cannot reach past the SDK.** Its entrypoint implements
   `Module` and answers declarative questions — *what gateways do you
   provide, what permissions do you need, where are your migrations* — and
   core does the wiring. There is no service provider handed to a module
   and no `$this->app`. A module that needs something core does not expose
   is a core change with an ADR, not a clever hook.

4. **Failure is contained.** A module that throws while registering is
   disabled with the reason recorded, and the installation keeps working.
   The alternative — one bad module taking the platform down — is how
   operators learn never to install one.

ADR 0038 records this.

## 4. The second decision: the SDK is platform contracts, versioned

The handoff asks for "platform-owned SDK contracts rather than framework
internals". Concretely:

- A module depends on `App\Domain\**\Contracts` and on `App\Domain\Modules`
  — value objects, enums and interfaces, with **no framework imports**,
  which the existing architecture test already enforces for `app/Domain`.
- A module never depends on an Eloquent model, a facade, a controller or a
  middleware. If it needs data, core passes it a value object.
- The SDK carries a **version**, declared in each manifest as a range. Core
  refuses a module built for an SDK it is not. This is what makes it
  possible to change an internal later without breaking every module
  silently — the refusal is loud and at install time.

ADR 0039 records this.

## 5. What gets built

### The package

```text
modules/<vendor>/<slug>/
  module.json            manifest: name, slug, type, version, provider,
                         sdk range, platform range, entrypoint,
                         permissions, dependencies
  src/                   PHP, PSR-4 under the manifest's namespace
  database/migrations/   optional, run on install and upgrade
  lang/<locale>/         optional
  README.md
```

`type` is one of the handoff's list: `payment-gateway`, `provisioning`,
`registrar`, `notification-channel`, `fraud`, `tax`, `report`,
`admin-widget`, `client-widget`, `addon`. The type is **descriptive** — it
decides how the module is grouped on the screen and which capabilities an
operator should expect — while what it actually registers comes from the
entrypoint. A module that claims one type and registers another is refused,
because a payment gateway that quietly registers a provisioning module is
exactly the surprise this list exists to prevent.

### The entrypoint

```php
interface Module
{
    public function gateways(): array;              // PaymentGateway[]
    public function provisioningModules(): array;   // ProvisioningModule[]
    public function registrars(): array;            // DomainRegistrar[]
    public function channels(): array;              // DeliversNotifications[]
    public function healthChecks(): array;          // HealthCheck[]
    public function permissions(): array;           // PermissionDefinition[]
    public function navigation(): array;            // NavigationItem[]
    public function widgets(): array;               // Widget[]
    public function configSchema(): array;          // ConfigField[]
    public function boot(ModuleContext $context): void;
}
```

`ModuleContext` carries the module's own configuration, its logger and its
slug — and nothing else. A default `BaseModule` returns empty arrays for
everything, so a module that provides one thing writes one method.

### Lifecycle

`install` → `enable` ⇄ `disable` → `upgrade` → guarded `uninstall`, each an
application use case, each audited.

- **Install** validates the manifest, checks the SDK and platform ranges
  and the declared dependencies, runs the module's migrations, syncs its
  permissions, and leaves it **disabled**. Installing is not consenting.
- **Enable** loads the entrypoint, registers everything it provides, and
  records the state. This is the audited moment.
- **Disable** unregisters it: a disabled gateway disappears from checkout,
  a disabled provisioning module leaves its services alone but refuses
  operations with a stated reason rather than failing obscurely. Disabling
  is reversible and loses nothing.
- **Upgrade** re-reads the manifest, refuses a version that moves
  backwards, runs new migrations and re-syncs permissions.
- **Uninstall is guarded.** It is refused while rows reference the module —
  a provisioning module with live services, a gateway with stored payment
  methods or transactions, a registrar with domains — and the refusal names
  what is in the way. It removes the module's row, its configuration and
  its permissions; it **never** drops the module's data tables. An operator
  who wants that data gone asks for it by hand, having read the sentence
  that says it cannot be undone.

### Configuration and secrets

A module declares a config schema: key, label, type, required, default. A
field of type `secret` is **encrypted at rest**, write-only in the screen
(the same treatment a server's control-panel token already gets), and
redacted in logs by the existing `SecretRedactor`. A module's configuration
is installation-wide in this phase; per-organization configuration is
Phase 13's, where resellers arrive.

### Permissions, navigation and widgets

- Permissions a module declares go through `SyncPermissions`, so they
  appear on the roles screen like any other and are **orphaned** on
  uninstall rather than deleted — the mechanism already exists and already
  survives a permission coming back.
- Navigation is declarative: label, route, permission, section. A module
  cannot put a row where it likes; it adds to the Extensions section.
- A module's routes mount under `/admin/modules/{slug}/…` inside the
  **core admin middleware group**, so the boundary, authentication and
  audit apply exactly as they do everywhere else. A module cannot register
  a route at `/login`.
- Widgets are **data**, not components: a title, rows, links and a
  permission, rendered by a core Vue component. A module shipping
  JavaScript would mean a build step per installation and an XSS surface
  inside the admin SPA; neither is worth it for Phase 12, and saying so now
  is better than discovering it in Phase 13.

### Health and logs

Each module may provide health checks, which join the Phase 9 health
screen with the module's name attached. Every module writes to a `module`
log channel with its slug in the context, so "what did that module do" is
answerable without reading everything else.

### The screens and the command line

- `/admin/modules` — installed modules by type, state, version, health,
  and the config form. Install, enable, disable, upgrade, uninstall.
- `php artisan module:list`, `module:install`, `module:enable`,
  `module:disable`, `module:uninstall`.
- `php artisan module:make` scaffolds a manifest, an entrypoint and a
  README into `modules/<vendor>/<slug>/`.

### The example module

One shipped module, of a type core has no implementation of, so that it
proves the SDK rather than duplicating something. It registers a
`RiskEvaluator` and a health check, declares a permission and a config
schema with a secret field, and ships a migration and translations — which
between them exercise every part of the lifecycle.

No core adapter is moved out into a module in this phase. Phase 11 moved
the core templates into `themes/storefront/core` to prove the theme chain,
and the same move would prove this one — but a provisioning module with
live services behind it is not the thing to move first, and the tests build
modules on disk exactly as the theme tests build themes.

## 6. Order of work

1. Migration: `modules`, with state, version and encrypted configuration.
2. Domain: `ModuleType`, `ModuleState`, `ModuleManifest`, `Module`,
   `BaseModule`, `ModuleContext`, `ConfigField`, `NavigationItem`,
   `Widget`, SDK version constant.
3. Infrastructure: manifest discovery and validation, the installed-module
   repository, the PSR-4 loader for enabled modules only.
4. Application: install, enable, disable, upgrade, uninstall, each with
   its guard and its audit record.
5. Wiring: the four registries, health checks, permissions, translations,
   migrations, navigation, widgets, routes.
6. The `/admin/modules` screen.
7. The artisan commands, including `module:make`.
8. The example module and `docs/modules/`.
9. Permissions, translations, ADRs 0038 and 0039, result document.

## 7. Definition of done for this phase

Everything in the standing list, plus:

- A module on disk but not installed is proven to change nothing.
- A module installed but not enabled is proven to register nothing.
- An enabled module is proven to reach each of the four registries.
- A module whose SDK range excludes this platform is proven to be refused,
  by name and reason.
- A module whose declared type does not match what it registers is proven
  to be refused.
- A module that throws while registering is proven to be disabled with the
  reason recorded, and the rest of the installation proven still to work.
- Uninstall is proven to be refused while a service, a payment method or a
  domain references the module, and the refusal proven to name it.
- A module's secret field is proven never to reach the browser and never to
  reach a log.
- A module's permissions are proven to appear on the roles screen and to be
  orphaned rather than deleted on uninstall.
- A module route is proven to be unreachable without the module's own
  permission, and proven to go through the organization boundary.
- `php artisan module:make` is proven to produce a module that installs,
  enables and passes its own health check.
