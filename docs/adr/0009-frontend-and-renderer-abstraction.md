# 0009 — Inertia for admin and client, renderer abstraction for the storefront

Status: accepted
Date: 2026-09-22

## Context

The platform has several front-end surfaces with different requirements. The
admin panel and client area are dense, stateful, authenticated applications
where a server-driven SPA removes an entire API-client layer. The public
storefront is the opposite: it must be indexable by search engines, themeable
with plain templates by people who are not Vue developers, and replaceable by
a headless front end for customers who want their own site.

Locking all three to Inertia would make the storefront requirement
unsatisfiable without a rewrite.

## Decision

- Admin and client areas are Inertia + Vue 3 + TypeScript applications, and
  will stay that way. Shared props are declared once in
  `HandleInertiaRequests` and typed once in `resources/js/types/inertia.d.ts`.
- The public storefront is rendered through the `StorefrontRenderer`
  contract. The default implementation resolves Blade templates through the
  theme precedence chain (installation override -> child theme -> parent
  theme -> core fallback). Swapping the binding is the whole migration to a
  different rendering strategy.
- UI permission checks (`usePermissions`) control what is *shown*. They are
  never the authorization decision: every action is re-checked server side by
  the gate of the same name.
- No third-party component library. The internal design system is built on
  semantic CSS custom properties so white-labelling in Phase 11 restyles
  components without changing them.

## Consequences

- Two rendering paths to maintain, which is the point.
- Theme authors work with Blade for the storefront and with Vue for the
  authenticated areas. This is documented rather than hidden.
- Server-side rendering for the Inertia apps is not configured in Phase 0 and
  is not needed: those surfaces are behind authentication.
