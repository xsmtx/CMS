# Phase 11 — Theme and White-Label Result

Status: complete
Date: 2026-09-23
Plan: `phase-11-plan.md`
Next phase: Phase 12 (Modules and Extensions) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **959 passed, 3580 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 23 passed |
| Build | `vite build` | pass |
| Migrations | 25 migrations on MariaDB 11.8, dropped and re-applied from empty | clean |

Phase 10 finished at 862 tests; this phase ends at 959. The branding and
theme work accounts for 32 of those; the rest came with the operator
screens this phase also delivered (below).

## 2. The decisions this phase turns on

**A brand is a row, not a config value**
([ADR 0036](../adr/0036-a-brand-is-a-row.md)).

`config('app.name')` is a deployment fact, read in fourteen places. A brand
is a business fact: it changes on a Tuesday afternoon, it differs between
resellers on one installation, and it ends up on documents a customer keeps
for years. One resolver replaced all fourteen, and a view composer means no
storefront controller passes a brand at all — the controllers got shorter.

A brand **inherits field by field** up the organization path. A reseller
that set a logo and nothing else shows the provider's colours, footer and
legal links rather than a half-branded page; a null and an empty string
both mean "ask my parent", or somebody who clears a field can never undo
it.

The hardest part was deciding **whose** brand, per surface. A customer is an
organization here, so "the current organization's brand" would show a
customer their own company name on their own invoices. The client area
resolves the **seller's** brand, through the same `ResolveSeller` that
numbers documents.

**A theme is a package, and it may not execute**
([ADR 0037](../adr/0037-a-theme-is-a-package-and-may-not-execute.md)).

The precedence chain — installation override → child → parent → core — is
**resolution, not merging**. A template is found at the first level that has
it, whole. Settings merge; templates do not, because a template assembled
from two files neither of whose authors saw the other is a bug nobody can
reason about.

It is registered as view paths in precedence order, which is Laravel's own
semantics for a namespaced view. The consequence is that **no controller
changed**: `storefront::catalog` now resolves through four directories
instead of one, and that is the entire feature.

A theme may not contain PHP that runs, and that is checked rather than
assumed. WHMCS template files are PHP, which makes "install this free
theme" a known attack; raw tags here are refused at install, with the file
named.

## 3. What was built

**Branding.** `brand_settings` per organization: trading and legal name,
tax id, address, support email and phone, website, logo (light and dark),
favicon, accent colour and its contrast pair, font family, portal name,
email from-name and from-address, email and invoice footers, legal links,
and the vendor-mark switch. Applied to the storefront shell, the client
shell, the admin shell, every notification's rendering and the mail
envelope's **from** address.

Colours reach the browser as custom properties on the document, overriding
the design tokens everything is already built on. There is no build step
between picking a colour and seeing it.

**Themes.** `themes/<surface>/<slug>/theme.json` with name, slug, version,
platform compatibility, parent and a settings schema; a registry that
validates manifests, resolves the parent chain and refuses a theme built
for a platform this is not; an installation override directory outside the
theme, so an operator's one-file change survives an upgrade. The core
storefront templates moved into `themes/storefront/core`, which is the
parent every other storefront theme inherits from — the chain is proven
rather than theoretical.

**Entitlements.** A contract with one question and a default that allows
everything, because a self-hosted installation with no licence server must
not be crippled by a check it cannot answer (ADR 0013, ADR 0022). Exactly
one feature is declared. Nothing in the codebase writes
`edition === 'enterprise'`.

**`/admin/settings`.** The navigation has pointed at it since Phase 0 and
no route had ever answered. It answers now: branding, the theme picker per
surface, and the entitlement-gated vendor-mark switch, all audited.

## 4. Also delivered in this phase

The phase ran alongside a run of operator-facing work that the plan did not
scope but the product needed:

- **WHMCS-shaped client search** — an advanced panel where every criterion
  offered is one that runs, the list columns an operator scans, and closed
  accounts hidden by default.
- **Manage users** — every person who can sign into the customer area,
  with a password reset and an audited password change.
- **Add new client** — the company, the first person and the address in one
  transaction, with `information_required` as a real status enforced by
  middleware, and three billing preferences each read by code that exists.
- **Products and services** and **Service addons** — the two list screens a
  WHMCS operator works from, with a filter panel, a type rail built from
  the rows, an expandable row detail and a hide-inactive toggle.
- **An addon is not a service** ([ADR 0035](../adr/0035-an-addon-is-not-a-service.md))
  — `service_addons`, which is what made the addons screen possible to
  build honestly.
- **A density and hierarchy pass** over the admin shell, checked against
  the built stylesheet in both themes.

## 5. Problems found

**`attributes()` is reserved on `FormRequest`.** A `BrandRequest::attributes()`
override meant to return brand fields replaced Laravel's own hook for
validation attribute names, and broke validation on that request. Renamed.
The lesson is narrower than it sounds: a base class's method names are its
API whether or not the child meant to participate.

**The vendor mark was gated on save but not on render.** An installation
whose licence lapsed would have kept the mark hidden forever, because
permission had been checked once, at the moment somebody pressed the
switch. Entitlements are now asked at render; the stored choice survives,
so restoring the licence restores the effect.

**`Customer::displayName()` lazy-loaded on Manage users.** The third time
this class of bug has been found. It is now stated once, in
`Customer::displayNameWith()`, and every list asks for it — see the note in
`CLAUDE.md`.

**`$order->items` excludes children on purpose**, so reading addon lines
off it found nothing at all. Caught by a test written before the screen.

**`information_required` is twenty characters in a column that was
sixteen.** MariaDB says so only at the moment somebody sets the status.

## 6. Carried risks

Unchanged from Phase 10, and stated again because they have not moved:

- **No provider adapter has ever talked to its real provider.** Stripe,
  cPanel and Namecheap are exercised against fakes. The contracts are
  right; the wire formats are unverified.
- **The client area has never been driven in a browser**, because signing
  in would mean typing a password. Every client screen has a feature test
  that renders it; none has been looked at by a human in this session.
- **Themes other than core do not exist yet.** The chain is proven by
  tests that build a theme on disk, not by a theme anybody has shipped.
