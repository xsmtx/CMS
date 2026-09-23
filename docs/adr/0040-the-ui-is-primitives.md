# 0040 — The UI is primitives, and the tokens are the API

- **Status:** Accepted
- **Date:** 2026-09-23
- **Handoff:** #3 §0, §2, §13, §16, §19.5
- **Related:** [0036 — A brand is a row](0036-a-brand-is-a-row.md), [0037 — A theme is a package and may not execute](0037-a-theme-is-a-package-and-may-not-execute.md)

## Context

Handoff #3 asks for three first-party themes that differ in "density,
geometry, navigation, cards, tables, charts and composition", for a theme SDK
that lets third parties override presentation, and for WCAG 2.2 AA
throughout. It also says, in §0, the thing that decides everything else:
**build one semantic design system, not three unrelated frontends.**

Three choices had to be made before any of that could be built, and §19.5
asks for them to be written down.

## Decision

### 1. No component library. Primitives this repository owns.

Sixteen primitives, none of them from a library. The reasons, in the order
they mattered:

- **A theme must be able to change geometry, density and composition.** Every
  library worth using ships its own opinions about all three, and a theme
  system layered on top of one becomes a fight with it — the second theme is
  where that fight is lost.
- **The bundle is served from the customer's own server.** An operator
  installs this on a box; there is no CDN and no build step on their side. A
  library that is 200 kB of components to use eleven of them is 200 kB in
  every page load forever.
- **Sixteen primitives is a smaller surface than one library's API.** The
  whole set is readable in an afternoon and every one of them is a file
  somebody here can change.

The cost is real: date pickers, virtualised tables and rich comboboxes are
work we will do ourselves or do without. That is accepted. The one place it
would be wrong to hold this line is a component where getting it wrong is an
accessibility failure nobody notices — a combobox, a modal focus trap — and
when one of those is needed, an unstyled primitive (Radix-style, headless) is
the answer rather than a styled library.

### 2. The custom properties are the public API; Tailwind's names are not.

A theme overrides `--surface-primary`. A brand overrides `--brand-primary`.
Those strings are what Handoff #3 §2 printed, and a third-party theme author
will read the handoff, not this repository.

Tailwind's colour tokens *resolve* those properties rather than holding
values, so overriding a property moves every utility with it and no
stylesheet is regenerated per brand.

Where Tailwind's own utility prefix already names the family, the Tailwind
name is shorter: `--color-content: var(--text-primary)` so markup reads
`text-content-muted` rather than `text-text-secondary`. Two spellings of one
vocabulary, mapped in one table in `docs/design/design-system.md`. The
alternative was six hundred occurrences of a name nobody can read, and
unreadable is how mistakes get made.

**`accent` changed meaning** in this decision. In the handoff, `accent` is
the cyan and `brand-primary` is the blue; ours had `accent` meaning the blue.
Leaving that would have been a trap for anybody reading the spec beside the
code, so the rename happened now, while it is a mechanical pass, rather than
after a theme SDK has shipped and it is a major version.

### 3. One icon family, `regular` weight, concepts rather than drawings.

Phosphor, at `regular` — a 1.5px stroke on a 24px grid, the same optical
weight as the hairlines this product draws its structure with. §13 says one
consistent outline family and no mixing; mixing weights is the fastest way to
make a set look assembled rather than chosen.

`resources/js/icons.ts` maps **concepts** to drawings: a screen asks for
`clients`, never for `PhUsers`. Changing a drawing is then one line, and two
screens cannot pick two different carts. The map is static so the bundle
carries only what is named.

Nothing is hand-drawn. A path somebody nudged by eye is a path nobody can
match when the next icon is needed.

### 4. A system font stack, and no webfont in core.

This product is installed on servers that may have no outbound network
access. Shipping a webfont would either add a CDN dependency at runtime or
bake a licence decision into core. A brand supplies its own face through
`--font-sans`, which is the same seam everything else uses.

§15 asks that fonts not cause layout shift. A system stack cannot.

## Consequences

`resources/css/app.css` is public API. A change to a token name there is a
change a third-party theme can see, and the only honest way to make a
breaking one is to version it the way the module SDK is versioned.

Every component is ours to fix and ours to get wrong. The accessibility
baseline in §14 is therefore a thing we implement rather than inherit, which
is why it is listed explicitly in the design system document rather than
assumed.

A theme cannot change behaviour, only presentation — that is ADR 0037 and it
still holds. Handoff §16 says the same: a theme "cannot replace
authorization/business actions".

The three themes will share every primitive. If a primitive ever needs a
per-theme branch inside it, that is the signal that the token taxonomy is
missing a family — not that the primitive needs a variant.
