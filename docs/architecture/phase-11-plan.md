# Phase 11 — Theme and White-Label Plan

Status: approved for implementation
Date: 2026-09-23
Scope: V2 roadmap Phase 11 — branding per organization, theme packages with
manifests and child themes, the override precedence chain, the settings
screen the navigation has been pointing at since Phase 0, and the
entitlement seam that decides whether vendor branding can be removed.

---

## 1. Starting point

The seam for this was cut in Phase 2 and has been sitting unused since.

- **`StorefrontRenderer` exists** and every public page already goes
  through it. Its docblock says "Phase 9 registers the theme view paths;
  until then only the core fallback namespace exists". This is that phase.
- **`themes/storefront`, `themes/client` and `themes/admin` exist** as
  empty directories, reserved in Phase 0.
- **The storefront already narrows to one organization**
  ([ADR 0020](../adr/0020-storefront-organization-boundary.md)) rather than
  to a boundary subtree, which is precisely the question "whose brand is
  this" needs answered.

And two things are visibly wrong today:

- **`brand` is `config('app.name')`, repeated in twelve controllers.** One
  installation-wide string, copied by hand, which cannot be per-reseller
  and cannot be per-brand.
- **`/admin/settings` is a dead link.** The navigation has offered it since
  Phase 0 and no route has ever answered it. A menu that advertises a
  screen that does not exist is a menu that lies, and this one has been
  lying for eleven phases.

## 2. The central decision: a brand is a row, not a config value

`config('app.name')` is a deployment fact. A brand is a business fact: it
changes without a deploy, it differs between resellers on one installation,
and it appears on documents a customer keeps.

**Branding belongs to an organization**, and it is resolved the same way
ownership already is:

| Surface | Whose brand |
| --- | --- |
| Storefront | The installation's organization (ADR 0020) |
| Client area | The organization that **sells** to this customer (`ResolveSeller`) |
| Admin | The staff member's own organization |
| Email, invoices, notifications | The organization the document belongs to |

One resolver — `CurrentBrand` — answers all four, and the twelve copies of
`config('app.name')` become one call. `config('app.name')` survives as the
fallback of last resort, for a fresh installation that has not been
branded yet.

**A brand inherits.** A reseller that has set a logo and nothing else gets
its parent's colours, footer and legal links, because the alternative is a
half-branded storefront and an operator who has to fill in thirty fields
before anything looks right. Inheritance walks the organization path, which
is the structure that already exists.

**Nothing a brand holds is a secret**, and that is enforced rather than
assumed: the row holds a name, an address, colours, URLs. An email
*identity* is here — the from-name and from-address — but the credentials
that send it stay in configuration, where the rest of this platform's
secrets live.

ADR 0035 records this.

## 3. The second decision: a theme is a package, and it may not execute

The precedence chain the handoff asks for is:

```text
installation override -> child theme -> parent theme -> core fallback
```

That is **resolution, not merging**. A template is found at the first level
that has it, whole. Merging templates — taking a header from one and a
footer from another — is a feature that sounds helpful and produces
theme bugs nobody can reason about. Settings *do* merge, because a child
theme that only wants to change one colour should not have to restate
twenty.

**A theme contains templates, assets, translations and a manifest. It may
not contain PHP that runs.** This is the decision the phase turns on.

A theme is content an operator downloads and installs. A theme that can
execute code is a remote-code-execution feature with a friendly name, and
every platform that has allowed it has regretted it — WHMCS template files
are PHP, and "install this free theme" is a known attack. So:

- Blade templates are compiled with the **same escaping and the same
  restrictions** as any other view, and the manifest is JSON rather than a
  PHP file that returns an array.
- A theme that needs logic gets it through data the controller already
  passes, or it does not get it. If a theme genuinely needs new behaviour,
  that is a **module** (Phase 12), which is a different trust decision with
  a different review.
- Raw PHP tags in theme templates are refused at install time, with the
  file named.

ADR 0036 records this.

## 4. Vendor branding and entitlements

The handoff says vendor-brand removal depends on the licence edition, and
[ADR 0013](../adr/0013-licensing-control-plane-separation.md) says the
licence control plane is a separate system this repository does not
contain.

So Phase 11 builds the **seam**, not the policy, exactly as Phase 3 did for
tax and risk ([ADR 0022](../adr/0022-risk-and-tax-are-contracts.md)):

- An `Entitlements` contract with one question, `allows(string $feature)`.
- A default implementation that **allows everything**, because a
  self-hosted installation with no licence server must not be crippled by
  a check it cannot answer.
- Exactly one entitlement declared: `branding.remove_vendor_mark`.
- Nothing in the codebase ever writes `edition === 'enterprise'`.

## 5. What gets built

### Branding

A `brand_settings` row per organization: trading and legal name, address,
support email and phone, logo and favicon, primary colour, font choice,
portal name, email from-name and from-address, invoice footer, legal links,
and the vendor-mark switch.

Applied to: the storefront shell, the client area shell, the admin shell,
every notification template's rendering, and the invoice document.

Colours reach the browser as **CSS custom properties on the document**, not
as a stylesheet a theme has to regenerate. The design tokens are already
custom properties; a brand overrides three of them and everything built on
them follows.

### Themes

- `themes/<surface>/<slug>/theme.json` — name, slug, version, platform
  compatibility, parent, supported surfaces, settings schema.
- A registry that reads manifests, validates them, resolves the parent
  chain and refuses a theme whose compatibility range excludes this
  platform version.
- View paths registered in precedence order, so `storefront::layout`
  resolves through the chain with no controller changes at all.
- An `installation override` directory, outside the theme, so an operator's
  one-file change survives a theme upgrade.
- Theme assets served from a public path, with the theme's own `theme.css`
  loaded after the core stylesheet.
- The core storefront templates move to `themes/storefront/core`, which is
  the parent every other storefront theme inherits from — proving the chain
  rather than leaving it theoretical.

### The settings screen

`/admin/settings`, finally. Branding, the theme picker per surface, and
the entitlement-gated vendor-mark switch. Everything on it is audited,
because "who changed the company's legal name on its invoices" is a real
question.

## 6. Order of work

1. Migration: `brand_settings`, `theme_settings`.
2. `Brand`, `CurrentBrand`, inheritance, and the twelve controllers.
3. `Entitlements` contract and its dull default.
4. Theme manifest, registry, validation, precedence registration.
5. Move the core storefront templates into `themes/storefront/core`.
6. Brand the client and admin shells, notifications and the invoice.
7. The settings screen.
8. Permissions, translations, ADRs 0035 and 0036, result document.

## 7. Definition of done for this phase

Everything in the standing list, plus:

- A reseller's storefront is proven to show the reseller's brand and never
  the provider's.
- A brand with one field set is proven to inherit the rest from its parent.
- The precedence chain is proven by a test that overrides one template at
  each level and asserts which one wins.
- A theme containing a raw PHP tag is proven to be refused at install, with
  the file named.
- A theme whose compatibility range excludes this version is proven to be
  refused.
- `config('app.name')` appears in no controller.
- No brand field can hold a secret, and a test asserts the shape.
