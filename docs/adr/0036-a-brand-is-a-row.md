# 0036 — A brand is a row, not a config value

**Status:** accepted
**Date:** 2026-09-23
**Supersedes:** nothing. Extends
[0020](0020-storefront-organization-boundary.md) and
[0022](0022-risk-and-tax-are-contracts.md).

## Context

Until Phase 11 the product's name came from `config('app.name')`, read in
fourteen places. That is a **deployment fact**: it changes when somebody
edits `.env` and restarts, it is the same for everybody on the
installation, and it cannot differ between two resellers selling from the
same database.

A brand is a **business fact**. It changes on a Tuesday afternoon because
the marketing department said so. It differs per reseller on a white-label
platform, which is the entire point of one. And it appears on documents a
customer keeps — an invoice with the wrong company name on it is a real
problem for somebody's accountant, years later.

There is also a subtler question that `config()` cannot answer at all:
**whose** brand. A customer in the client area should see the brand of the
company that sells to them, not the platform vendor's and not their own —
and in this platform a customer *is* an organization, so "the current
organization's brand" would show a customer their own company name on
their own invoices.

## Decision

**Branding is a `brand_settings` row per organization**, resolved the way
ownership already is.

1. **One resolver answers every surface.** `CurrentBrand` decides whose
   brand applies, and the four answers are different questions:

   | Surface | Whose brand |
   | --- | --- |
   | Storefront | The installation's organization (ADR 0020) |
   | Client area | The organization that **sells** to this customer (`ResolveSeller`) |
   | Admin | The staff member's own organization |
   | Email, invoices, notifications | The organization the document belongs to |

   `config('app.name')` survives as the fallback of last resort, for a
   fresh installation nobody has branded yet.

2. **A brand inherits, field by field.** `ResolveBrand` walks the
   organization path nearest-first and stops at the first non-null *per
   field*, not per row. A reseller that has set a logo and nothing else
   shows its parent's colours, footer and legal links rather than a
   half-branded page. Requiring thirty fields before anything looks right
   is how a white-label feature goes unused.

3. **A null and an empty string both mean "ask my parent".** Treating them
   differently would make a field somebody deliberately cleared look set,
   and leave them with no way to undo a change.

4. **The walk reads past the organization boundary, narrowed to exactly
   the ancestor ids.** A brand is inherited from above and an ancestor is
   never inside its descendant's subtree, so the boundary cannot answer
   this question. It is the same escape `ResolveSeller` makes, for the same
   reason, and it is narrowed the same way.

5. **Colours reach the browser as custom properties on the document.** The
   design tokens are already custom properties; a brand overrides three of
   them and everything built on them follows. No stylesheet is regenerated,
   and there is no build step between picking a colour and seeing it.

6. **Nothing a brand holds is a secret**, and a test asserts the shape
   rather than trusting the convention. The row holds a name, an address,
   colours and URLs. The email *identity* lives here — the from-name and
   from-address, which every recipient sees anyway — while the credentials
   that send that mail stay in configuration with the rest of this
   platform's secrets. A brand is handed to Blade templates a theme author
   wrote; everything on it must be safe to print.

7. **Vendor-mark removal is an entitlement, not an edition check.** ADR
   0013 puts the licence control plane in a different system, so Phase 11
   builds the seam and not the policy, exactly as Phase 3 did for tax and
   risk (ADR 0022): an `Entitlements` contract with one question, a default
   implementation that **allows everything**, and one declared feature. A
   gate whose default is deny turns an unreachable licence API into an
   outage nobody can distinguish from the product being broken.

   The gate is applied at **render**, not only on save. A lapsed licence
   shows the mark again rather than leaving it hidden forever because
   somebody once had permission to hide it.

## Consequences

Fourteen copies of `config('app.name')` became one call, and a view
composer means no storefront controller passes a brand at all — the
controllers got shorter rather than longer.

Resolution is memoised per request: a storefront page asks for the brand in
the layout, the header, the footer and the invoice partial, and four
identical walks up a path is three too many. The memo is cleared when a
brand is saved, because the screen that saved it rendering the old one is
the commonest way a cache like this is wrong, and it is wrong in the one
moment somebody is watching.

Every brand change is audited. "Who changed the company's legal name on its
invoices" gets asked exactly once, in circumstances nobody enjoys.
