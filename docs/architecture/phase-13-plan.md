# Phase 13 — Reseller Plan

Status: in progress
Date: 2026-09-23
Previous: `phase-12-result.md`
Handoff: §2 (Reseller-Ready Ownership Model), §4 (Reseller Area), §22 Phase 13

---

## 1. What already exists

Most of the isolation this phase is named for was built in Phase 0 and has
been carried since. Writing that down first, because the temptation in a
reseller phase is to build a second copy of the admin area.

- **The boundary.** `ResolveOrganizationContext` sets the organization from
  the authenticated actor, ahead of `SubstituteBindings`. Every owned table
  carries `organization_id` and its model uses `BelongsToOrganization`. A
  reseller's staff signing into `/admin` already sees only their own subtree
  — customers, services, domains, invoices, tickets, tokens, webhooks.
- **The tree.** `OrganizationType` has `Reseller`, `canOwnChildren()` and
  `permittedChildTypes()`. `Organization::owns()` and `path` are the
  materialised ancestry.
- **The seller.** `ResolveSeller` answers "who sells to this organization",
  and document numbering, support departments and dunning all use it. An
  invoice a reseller's customer receives is already numbered in the
  reseller's name.
- **The brand.** `CurrentBrand` resolves the **seller's** brand per surface
  (ADR 0036), so a reseller's customer already sees the reseller's name on
  their portal and their invoices.
- **The entitlement seam.** `Entitlements::allows()` exists with a dull
  default.

So this phase is not "build the reseller area". The reseller area is the
admin area, narrowed. This phase is the four things that narrowing does
**not** give you, plus the screens to operate them.

## 2. What is missing

**A reseller cannot be created.** `/admin/organizations` is read-only, and
deliberately so: "an organization is created by the thing that needs one".
Nothing needs a reseller yet. That is the first slice.

**A reseller sells everything.** There is no notion of which of the
provider's products a reseller may offer. The storefront narrows to one
organization; the catalogue does not narrow at all.

**A reseller cannot price.** The price matrix is the provider's. A reseller
has no way to add a margin or to set an exact number, so every reseller
sells at the provider's price and makes nothing.

**A reseller has no balance with the provider.** Credit exists per
*customer* (`Ledger`, `transactions.credit_balance_minor`). A reseller is
an organization, not a customer, so there is nowhere to record what the
reseller owes or holds.

**A reseller cannot see what they sold.** No reports.

## 3. Slices

Each is a commit with its gates green.

1. **Create and manage a reseller.** `CreateReseller` writes the
   organization and its first staff account. A reseller may create a
   reseller? No — `permittedChildTypes()` already says a reseller may only
   own customers, and that stands: one level of resale, because a margin on
   a margin on a margin is a support ticket nobody can answer.
2. **Product availability.** Which products a reseller may sell.
3. **Margins and price overrides.** `ResolveSellingPrice`, and every pricing
   path asks it.
4. **Reseller balance.** What the reseller owes the provider, and what they
   hold.
5. **Reseller reports.** What a reseller sold, and what the provider sold
   through its resellers.
6. **Hide what a reseller must not see.** An audit of every admin screen
   against a reseller actor, with a test that drives one.

## 4. Decisions to make, and the ones already made

**One level of resale.** `permittedChildTypes()` already says a reseller
owns customers and nothing else. Keeping it: sub-resellers multiply the
pricing question by themselves and the handoff does not ask for them.

**Pricing resolves, it does not merge.** The same rule themes got in ADR
0037. For what a reseller's customer pays: an explicit reseller price wins;
failing that a margin, most specific first (product → group → reseller);
failing that the provider's price. One winner, never an average of two.

**A margin is arithmetic on integer minor units.** Not a float, and not a
conversion at display time. A percentage is stored as a decimal string and
applied with integer arithmetic, and the result is a price like any other —
which the order line then copies (ADR 0021), so a margin change never moves
a document that already exists.

**Absence still means not sold.** A reseller with no availability rows sells
nothing, not everything. The alternative — absence meaning "all products" —
is a reseller created on Friday selling a product the provider had not meant
to expose, and there is no way to notice.

That last one deserves its own line because it is the opposite of the
price matrix's rule, where absence means not sold *for that currency*. Both
are "absence is a refusal", which is the rule this platform keeps.

**A reseller's balance is a ledger, not a column.** Financial history is
append-only here. A reseller balance that was a number on the organization
row would be a number somebody could edit.

## 5. Not in this phase

- **Invoicing a reseller for what its customers bought.** The handoff says
  full reseller commercial logic may ship later. What this phase builds is
  the balance and the movements; deciding *when* the provider bills a
  reseller — per order, monthly, on a threshold — is a billing model, and
  guessing it would mean rewriting it.
- **Sub-resellers.**
- **A separate reseller theme surface.** `Surface` already has what it
  needs and `CurrentBrand` resolves the seller's brand; a reseller-specific
  theme is a Phase 11 concern that Phase 11 finished.
