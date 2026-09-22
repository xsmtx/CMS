# 0001 — Modular monolith with four layers

Status: accepted
Date: 2026-09-22

## Context

The platform spans billing, provisioning, domains, support, licensing and a
public API. That breadth invites two failure modes. Splitting into services
immediately would multiply operational cost before a single customer exists,
and the product must remain installable by a single operator on one server.
Writing it as an undifferentiated Laravel application would let billing reach
into provisioning tables and make every later extraction impossible.

## Decision

A modular monolith with four layers inside `app/`:

- `Domain` — entities, value objects, enums and contracts. No framework
  imports at all.
- `Application` — one class per use case, orchestrating domain objects. May
  depend on `Domain` and on contracts.
- `Infrastructure` — Eloquent models, repositories, provider adapters, cache
  and queue implementations. Depends inward only.
- `Http` — controllers, middleware, form requests. Validates, authorizes,
  calls an application service, renders. No business rules.

`Support` holds cross-cutting technical concerns that are not a bounded
context: correlation identifiers, the error envelope, logging and redaction.

Contexts communicate through application services and domain events, never by
reading each other's tables. Eloquent models live in per-context namespaces
(`App\Infrastructure\<Context>\Models`), not in a global `App\Models`.

The rules are enforced by architecture tests in `tests/Arch`, so a violation
fails CI instead of relying on review.

## Consequences

- Extraction to a separate service later is a matter of replacing an
  `Infrastructure` implementation, not of untangling call sites.
- Some indirection exists that a small application would not need. This is
  accepted: the alternative is unpickable coupling at month eighteen.
- Laravel conventions that assume `App\Models` need explicit configuration —
  notably factory resolution, which is configured in `AppServiceProvider`.
