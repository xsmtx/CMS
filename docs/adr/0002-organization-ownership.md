# 0002 — Organization ownership boundary

Status: accepted
Date: 2026-09-22

## Context

The platform must support resellers: an organization that sells the
provider's products under its own brand and owns its own customers. A
reseller must never see another reseller's customers, invoices, services,
domains, tickets, API tokens, webhooks or reports.

Retrofitting tenancy is the single most expensive change a platform of this
shape can face. Doing it later means backfilling an owner column onto every
table, auditing every query written until then, and hoping none was missed.
The cost of introducing it in Phase 0, when there are two owned tables, is
close to zero.

## Decision

Introduce `organizations` in the first migration, before any other table.

- Exactly one `provider` organization is the root and owns the installation.
- `reseller` organizations are children of the provider; `customer`
  organizations are children of the provider or of a reseller. The hierarchy
  is enforced by the model, not by convention.
- Each row stores a materialised `path` (`/<root>/<child>/<leaf>/`). "Every
  organization at or below X" is an indexed prefix match rather than a
  recursive query, which matters because that predicate runs on every listing
  in the product.
- Every owned table carries `organization_id`. The `BelongsToOrganization`
  trait adds a global scope that filters reads to the acting organization's
  subtree, and a creating hook that stamps writes with the acting
  organization. Creating an owned record with no boundary throws.
- The boundary for a unit of work lives in `OrganizationContext`, backed by
  Laravel's `Context` so a queued job inherits the boundary of the request
  that dispatched it.
- The boundary is derived from the authenticated actor by middleware. It is
  never read from a header, query parameter or request body.
- `OrganizationContext::withoutBoundary()` is the single, greppable escape
  hatch for provider-wide reporting, the installer and maintenance commands.

Authorization is therefore two independent questions, asked in order:
`actor -> organization boundary -> resource ownership -> permission`. The
boundary decides which rows exist; permissions decide what may be done to
them. Neither substitutes for the other.

## Consequences

- Cross-organization queries must be deliberate and are easy to find in
  review.
- Roles and permissions remain installation-global in Phase 0. Per-reseller
  roles, if the commercial model needs them, are an additive change.
- Tests must establish a boundary; factories honour the ambient one so this
  is not onerous.
- A model that forgets the trait is not protected. The architecture tests
  grow a rule for this as soon as the second owned context lands.
