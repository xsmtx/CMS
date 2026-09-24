# Modules and the Marketplace — Plan

Status: planned
Date: 2026-09-24
ADRs: [0038](../adr/0038-a-module-may-execute.md),
[0039](../adr/0039-the-sdk-is-platform-contracts.md),
[0047](../adr/0047-a-package-is-verified-before-it-touches-disk.md)

Two questions, and the second only makes sense once the first is answered:
**which capabilities are modules**, and **how does one get onto an installation
without an operator using a file manager**.

---

## 1. The rule that decides it

ADR 0038 and handoff #2 §2 already settled the principle; this is it in one
sentence.

> **Core owns the seam. A module owns the thing on the other side of it.**

Core knows that a payment must be taken, that a service must be provisioned,
that a name must be registered, that a metric must be normalised, that tax must
be worked out. It must never know *Stripe*, *cPanel*, *Namecheap*, *Prometheus*
or one country's tax law — because each of those is somebody else's product or
somebody else's legislature, and both change on a schedule this repository does
not control.

Three consequences follow, and each has already bitten something:

**A seam with a dull default is core.** `TaxCalculator` and `RiskEvaluator` are
contracts with implementations core ships (ADR 0022, ADR 0045), because an
installation must be able to sell something before anybody configures anything.
Tax rules are rows rather than a module for the same reason — an operator in a
new country writes a row, not a package.

**A vocabulary is core; a noun in it may not be.** `ResourceKind` is an open
vocabulary and `Relation` is a closed one (ADR 0043): core must know which edges
mean "inside", and cannot know every noun a module will discover.

**"An operator does it by hand" is always a real answer, and always core.**
`ManualGateway`, `ManualModule` and `ManualRegistrar` stay in core permanently.
They are what makes every provider optional, and a platform whose only payment
method is a package somebody has to install is a platform that cannot take money
on day one.

## 2. What moves out of core

Three adapters are in `app/Infrastructure` today that the rule above puts in
packages. All three are already behind their contracts, which is what makes the
move a move rather than a rewrite.

| Today | Becomes | Type |
| --- | --- | --- |
| `Billing/Gateways/StripeGateway` | `gateway-stripe` | `ModuleType::PaymentGateway` |
| `Provisioning/Modules/CpanelModule` | `provisioning-cpanel` | `ModuleType::Provisioning` |
| `Domains/Registrars/NamecheapRegistrar` | `registrar-namecheap` | `ModuleType::Registrar` |

PayPal, which does not exist yet, is written as `gateway-paypal` and never lands
in core at all.

**Why now and not at the start.** Because none of them has ever spoken to its
real provider. An adapter that has never been proven against the thing it adapts
is exactly the code that should not be in the distribution every installation
runs: the day Stripe changes a field, a core release is the wrong unit of
shipping, and a package version is the right one.

**What the move actually costs.** Each adapter's credentials move from
`config/platform.php` to the module's own config schema, which is better than
where they are: a module's config is masked by the presenter and never leaves
`ModuleContext`, whereas an environment file is read by everything. Each keeps
its tests; the tests move with the package, and `tests/Feature/CpanelModuleTest.php`
becomes the package's own.

**This is a migration, not a deletion.** An installation upgrading across it must
find its gateway still working, so the move ships with the packages pre-installed
and enabled for any installation whose environment already configured them. A
release that silently stopped taking payments would be the worst upgrade this
product could ship.

## 3. What is already planned as a module

`advanced-operations-plan.md` §3 maps all twenty-four handoff #2 families to
slugs and phases; it is not copied here. What matters for the marketplace is the
shape it produces: **roughly forty packages**, most of them adapters for a named
third-party system, each versioned apart from core, each declaring the SDK range
it was built for.

Forty packages is the number that makes a marketplace necessary rather than
decorative. Three packages can be copied into `modules/` by hand. Forty cannot —
and an operator who must find, download, unzip and place each one will place one
of them wrong.

## 4. The marketplace

**It is a catalogue the vendor publishes, and this repository does not contain
it.** Exactly the shape the licence control plane already has: a contract, a
documented API (`docs/marketplace/api.md`), a fake that the tests drive, and an
`Unconfigured…` client so an installation with no marketplace URL behaves as it
always has rather than erroring.

**Only the vendor signs packages, in this first release.** Third-party
publishing needs a review pipeline, a revocation story and a disclosure process,
and none of those exist — offering it without them would be offering a promise
this product cannot keep. The catalogue may *list* somebody else's work; the
vendor is the one who signs what an installation downloads.

**Four steps, not three.** ADR 0038 established that installing is not enabling,
because enabling is the moment somebody else's code runs. The marketplace adds a
step before both:

```text
fetch     download bytes, verify them, unpack them     runs nothing
install   read the manifest, write a row               runs nothing
enable    migrations, registration, boot               runs the package
disable   unregister                                   stops running it
```

Each is audited separately, because each is a different thing an operator agreed
to. ADR 0047 covers what "verify them" means and why nothing is unpacked before
it happens.

**A licence decides what is offered, not what is refused.** The catalogue request
carries the installation's licence key, and the vendor returns what this
installation may have. Core does not hold a list of paid modules and does not
gate on one: a gate whose default is deny turns an unreachable vendor into an
outage (ADR 0041), and a catalogue that simply does not list something is not an
outage at all.

**An installation may switch the whole thing off.** `platform.modules.enabled`
already means "no third-party code runs here". The marketplace screen is absent
when it is false, and `FetchPackage` refuses before it opens a socket.

## 5. Sequencing

1. The marketplace itself, proven end to end against a fake vendor, with the
   existing `status-board` example served through it. *This lands first, because
   the three adapter moves below need somewhere to be delivered.*
2. `gateway-stripe`, `provisioning-cpanel`, `registrar-namecheap` extracted from
   core, with the upgrade path in §2.
3. Handoff #2's families, in their own phases, delivered through the
   marketplace rather than copied into `modules/`.

## 6. Out of scope, deliberately

- **Third-party publishing.** §4.
- **Paid checkout inside the panel.** Buying a module is a transaction with the
  vendor, and this platform is not going to grow a second billing system pointed
  at itself. The catalogue links out; the licence is what changes afterwards.
- **Automatic updates.** A module that upgraded itself would run new code nobody
  agreed to run, which is precisely what ADR 0038 exists to prevent. The
  marketplace *tells* an operator a newer version exists; upgrading is a button
  somebody presses.
