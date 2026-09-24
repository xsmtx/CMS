# The Marketplace — Result

Status: complete
Date: 2026-09-24
Plan: `modules-and-marketplace-plan.md`
ADR: [0047](../adr/0047-a-package-is-verified-before-it-touches-disk.md)
Contract: `docs/marketplace/api.md`

Two things were asked for: **design these capabilities as modules**, and **a
place users download modules from and install automatically**. The first was
largely already decided — `advanced-operations-plan.md` §3 maps twenty-four
families to slugs — so the design work was the part that was not: the provider
adapters still sitting in core. The second is this.

---

## 1. What shipped

- **`MarketplaceClient`** — the contract, with `HttpMarketplaceClient`,
  `UnconfiguredMarketplaceClient` and `Tests\Support\FakeMarketplaceClient`.
- **`VerifyPackage`** — size, SHA-256, Ed25519 over the archive bytes.
- **`UnpackPackage`** — entry by entry, into a staging directory.
- **`InstallFromMarketplace`** — fetch, unpack, then `InstallModule`. Two audit
  rows, and nothing executes.
- **`modules.source` / `modules.origin_digest`** — provenance.
- **`/admin/apps/marketplace`** — owner-only, beside Modules on the Setup page.
- **`docs/marketplace/api.md`** — the vendor API this repository does not
  contain.

## 2. The decisions worth keeping

**Four steps, not three.** ADR 0038 established that installing is not enabling,
because enabling runs somebody else's code. It never had to answer what happens
between "an operator pressed Install" and "a row exists", because with a package
copied into `modules/` by hand the operator chose the bytes. With a download they
chose **a name in a catalogue**, and something else chose the bytes. So fetching
is its own audited moment.

**Nothing is unpacked before it is proven.** An archive entry is a *path the
archive chooses*, and `../../../.env` is a valid entry name. Unpacking first
would mean an unsigned stranger deciding where this process writes. The order is
size → digest → signature → unpack → slug, and a refusal at any step deletes the
temporary file and writes nothing.

**The digest is not the security control and the signature is.** SHA-256 catches
a truncated transfer and a corrupted mirror. The catalogue that named the digest
could itself be the attacker; only Ed25519 against a key in the distribution says
otherwise — the installation can prove a package came from the vendor and cannot
mint one, which is the licence token's property (ADR 0041) reused.

**The packaging key is not the licence key.** They prove different things and one
compromise must not be both.

**There is no flag to skip verification.** An installation that wants to run
unsigned code can still put a directory in `modules/`, which is a deliberate act
on a machine somebody controls. A download that skipped the signature would be
the same act with none of the deliberation, and a setting for it is a setting
somebody turns on to make an error go away.

**Every refusal is named separately.** "The download failed" would cover a mirror
that truncated a file and somebody serving a package they did not sign, and those
must never look the same in an audit log: the first is an afternoon's annoyance
and the second is an incident.

**The licence decides what is offered, not what is refused.** The catalogue
request carries the licence key and the vendor answers with the subset this
installation may have. Core holds no list of paid modules and gates on nothing —
a catalogue that does not list something is not an outage, and a local gate whose
default is deny would be.

**An unreachable vendor is an empty catalogue.** `catalogue()` swallows and
returns nothing; `download()` throws, because an operator pressed a button and is
owed an answer. Everything already installed keeps running either way.

**The marketplace will not replace a module it did not deliver.** `source` is
`disk` for every row that already exists, and a catalogue entry offering that
slug is refused. Otherwise the vendor could quietly replace a hand-installed
package with its own.

**The provider directory name is built from a remote answer**, so it is reduced
to `[a-z0-9-]` with **no dots at all** — stricter than a slug needs to be, because
a provider called `..` with dots allowed is a path traversal assembled out of a
field somebody else filled in.

## 3. What the tests cover

`tests/Feature/MarketplaceTest.php` — 12 cases, all against **real zip files on
disk, a real Ed25519 keypair and the real verifier**. A fake that skipped
verification would prove that the happy path works and nothing else, which is the
opposite of what the fake is for.

The happy path (files unpacked, row written, state `installed`, two audit rows,
nothing enabled), and then the refusals, which are the feature: a package signed
with somebody else's key; bytes changed after the offer; an archive containing
`../../../escaped.txt`, asserted not to exist afterwards; a package declaring a
different slug from the one offered; modules switched off entirely; a module
placed by hand; and no packaging key at all. Then the screen: owner-only on read
and write, the catalogue listed and installed from, a refusal rendered as a form
error rather than a 500, and an empty catalogue when nothing is configured.

## 4. Three things the tests found

**The modules table is called `modules`, not `module_records`.** The model says
so; the migration assumed the model's name.

**`ModuleCatalogue` fixes its root when it is first built**, and the provider's
`boot()` builds one. A test setting `platform.modules.path` afterwards was
pointing a catalogue at a path it had already decided not to use — `forget()`
clears the memo, not the root. The fix in the test is `forgetInstance()`, and the
lesson is that a singleton reading config in its constructor is configuration
frozen at boot.

**An Inertia form error needs a field.** The refusal was returned under
`package`, which `useForm({ slug })` cannot type and which nobody connects to
what they pressed. It is against `slug` now.

## 5. Gates

Pint, Rector, PHPStan level 8, Pest (1636 passed), ESLint, Prettier, vue-tsc,
Vitest (85 passed), Vite build, `platform:openapi --check`. All green, and the
screen opened in a browser afterwards.

## 6. Next, per the plan

1. **Extract `gateway-stripe`, `provisioning-cpanel`, `registrar-namecheap`** from
   core into packages, with the upgrade path in the plan's §2 — an installation
   crossing that release must find its gateway still working.
2. Handoff #2's families, delivered through the marketplace rather than copied
   into `modules/`.

Neither is started.

## 7. Out of scope, deliberately

Third-party publishing (needs a review pipeline, a revocation story and a
disclosure process), checkout inside the panel (buying is a transaction with the
vendor, and this platform is not growing a second billing system pointed at
itself), and automatic updates (a module that upgraded itself would run new code
nobody agreed to run). The catalogue *says* a newer version exists; upgrading is
a button somebody presses.
