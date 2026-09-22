# 0020 — The storefront's boundary comes from the installation

Status: accepted
Date: 2026-09-24

## Context

Every owned record is filtered by the organization boundary, which is taken
from the authenticated actor ([ADR 0002](0002-organization-ownership.md)).
The public storefront has no actor. Without a boundary the global scope
applies no filter at all, so the first visitor to the catalog would see
every reseller's products in one list.

There is a second, subtler problem. The boundary is a *subtree*: a provider
can reach everything its resellers own, which is correct for administration.
A storefront asks a different question — not "what may this actor reach" but
"what does this brand sell" — and the subtree answer is wrong for it.

## Decision

Public routes run through `ResolveStorefrontOrganization`, which sets the
boundary to the provider organization at the root of the hierarchy when no
actor has already set one. It reads nothing from the request: a boundary
that a query parameter could choose would not be a boundary.

The catalog reader (`StorefrontCatalog`) then narrows further, to exactly
that one organization rather than its subtree.

Serving a reseller's own storefront from its own hostname is white-labelling
work, and belongs with the rest of it in Phase 11. Until then a single-brand
installation behaves correctly, and a multi-brand one shows the provider's
catalog on the shared domain — which is what it is.

## Consequences

- A signed-in staff member browsing the storefront keeps their own boundary
  and sees the catalog they administer. That is deliberate: it makes
  previewing a reseller's catalog a matter of signing in as that reseller.
- Phase 11 replaces only the resolution step: hostname to organization,
  everything downstream unchanged.
- The exact-organization rule lives in one method, so widening it later
  (a reseller inheriting the provider's catalog, say) is one edit with one
  test in front of it.
