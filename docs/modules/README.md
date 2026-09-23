# Writing a module

A module is a directory somebody drops into `modules/`. It can run code —
that is the whole point of it, and the thing that separates a module from a
theme — so the platform is careful about **when** that happens and **what**
the code can reach.

Two decisions shape everything below, and both are worth reading before you
write much:

- [ADR 0038 — A module may execute, and enabling is the moment it does](../adr/0038-a-module-may-execute.md)
- [ADR 0039 — The SDK is platform contracts, versioned apart from the platform](../adr/0039-the-sdk-is-platform-contracts.md)

The worked example is `modules/example/status-board` in this repository.
It is small, it is driven end to end by `tests/Feature/ExampleModuleTest.php`,
and nothing in core references it.

## Start

```bash
php artisan module:make acme-gateway --vendor=acme --type=payment-gateway
php artisan module:list
```

That writes a manifest, an entrypoint that compiles, and a README. It does
not scaffold a gateway: a stub implementing a contract it does not mean is
a stub somebody ships.

## The shape on disk

```text
modules/
  <vendor>/
    <slug>/
      module.json        what it claims to be
      src/               your PHP, autoloaded from the declared namespace
      database/migrations/   optional, run at enable
      lang/              optional
```

The vendor level exists so two authors can both ship a module called
`slack` without one overwriting the other. The **slug** still has to be
unique across the installation; a collision is refused rather than
silently resolved.

## The manifest

`module.json` is JSON and not PHP, because it is read **before** anybody
has decided to trust the package. A manifest that was a PHP file returning
an array would run your code the moment the platform first looked at it,
which is exactly the decision the install screen exists to put in front of
an operator.

```json
{
  "slug": "acme-gateway",
  "name": "Acme Payments",
  "type": "payment-gateway",
  "version": "1.2.0",
  "description": "One sentence an operator can read.",
  "provider": "Acme Ltd",
  "sdk": ">=1.0",
  "platform": "*",
  "namespace": "Acme\\Gateway",
  "entrypoint": "AcmeGatewayModule",
  "dependencies": [],
  "migrations": true,
  "translations": false,
  "config": [
    { "key": "api_key", "label": "API key", "type": "secret", "required": true },
    { "key": "sandbox", "label": "Sandbox mode", "type": "boolean", "default": true }
  ]
}
```

### `type`

The type is **checked**, not decorative. A module that declares itself a
`report` and registers a payment gateway is refused. The type is the one
thing most operators actually read before installing, so a package that can
quietly become something else is a package whose type means nothing.

`addon` is the honest escape hatch: a package that genuinely provides
several things declares itself an addon, and an operator reading "addon"
knows to look at what it registers rather than trusting the word.

### `sdk` and `platform`

Two ranges, checked separately, because they fail for different reasons and
an operator needs to know which. `platform` means "built for a different
version of the product". `sdk` means "built against a different set of
contracts" — that is the one that lets core change an interface and have
every module refuse loudly at install time rather than break quietly at
runtime.

A range nobody can parse is **refused**, not assumed permissive.

### `config`

Declare your settings here as well as in `configSchema()`. This copy is the
one that matters before your module runs: a module with a required field
that only declared it in code could never be enabled, because it cannot be
configured until it runs and cannot run until it is configured.

Once the module is running, `configSchema()` wins — that is where you
compute options that only exist at runtime.

Field types: `text`, `secret`, `boolean`, `number`, `select`, `url`. A
`secret` is encrypted at rest, never echoed back to the screen, and never
logged.

## The entrypoint

```php
final class AcmeGatewayModule extends BaseModule
{
    public function gateways(): array
    {
        return [new AcmeGateway(/* ... */)];
    }

    public function boot(ModuleContext $context): void
    {
        $this->key = $context->string('api_key');
    }
}
```

`BaseModule` answers every question with an empty list, so a module that
provides one gateway implements one method.

### What you are given

`ModuleContext` and nothing else: your slug, your configuration (already
decrypted) and your own logger. Not the container, not the request, not a
database connection, not the event dispatcher.

### What you are not given

There is no service provider handed over. A module cannot register
middleware, replace a binding, add a global scope or reorder the pipeline,
because it is never given the chance to try.

The cost is that a module cannot do something core has not anticipated.
That is the trade the SDK makes deliberately: an extension point added
later is a normal change, and an extension point that turned out to mean
"anything at all" cannot be taken back once modules depend on it. If you
need a seam that does not exist, ask for the seam.

### Every method is asked twice

Once before `boot()` — so a module claiming the wrong type is refused
before it has had a chance to do anything — and once after, because that is
the answer that matters. Work out what you provide from your configuration
in `boot()`, and return it from the registration methods afterwards.

### Throwing

Anything thrown from `boot()` leaves the module **disabled with the reason
recorded**. Throw when you cannot work. Do not carry on half configured: a
gateway registered without its API key fails at the moment a customer is
waiting, which is the failure the registries exist to avoid.

## The lifecycle

| Step | What happens | Does your code run? |
| --- | --- | --- |
| On disk | The manifest is read | No |
| Install | A row is written | No |
| Configure | An operator fills in your `config` fields | No |
| **Enable** | Dependencies checked, config checked, migrations run, entrypoint constructed, inspected, booted | **Yes** |
| Disable | The row is marked, the registries forget it | No |
| Uninstall | Refused if what you registered is still in use | No |

Uninstall refuses **without loading your package**: what you registered is
written onto the row at enable time, so "this module provides the gateway
three subscriptions pay through" can be answered about a module nobody
wants to run.

## Rules for the things you provide

- **A health check never throws and never returns a configuration value.**
  Not a DSN, not a host, not the URL you are watching. A health page is
  opened when something is already broken; a check that died would have
  taken down the one screen that was going to explain why.
- **A widget is data, not a component.** Rows with labels and values. A
  module shipping JavaScript would mean a build step on every installation
  and an XSS surface inside the admin app.
- **Navigation lands in the Extensions section**, not wherever you would
  like. A module that could put a row next to Billing could put a row that
  looks like Billing.
- **A permission you declare becomes a real permission** on the roles
  screen, and is orphaned rather than deleted if your module goes away —
  because a role that quietly loses a permission is a role nobody notices
  changed.
- **A provider adapter never touches the database.** It takes a value
  object and returns one. Recording what happened is core's job, done once,
  in one place.

## Commands

```bash
php artisan module:make <slug> [--vendor=] [--type=] [--name=]
php artisan module:list [--state=installed|enabled|disabled|failed]
```

Both read only. Running `module:list` on a box is not a decision about
anything.

## Turning it all off

```dotenv
PLATFORM_MODULES_ENABLED=false
```

An installation that has decided no third-party code runs on it says so
once, in configuration, rather than by an operator remembering not to press
a button.
