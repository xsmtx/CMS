# 0015 — Module SDK boundary

Status: accepted
Date: 2026-09-22

## Context

Payment gateways, provisioning panels, registrars, fraud services and tax
engines are all third-party integrations, and the commercial model depends on
people outside the core team writing them. If modules are written against
Laravel internals and Eloquent models, every framework upgrade and every
internal refactor breaks the ecosystem, and the platform becomes unable to
change its own code.

## Decision

Modules depend on platform-owned contracts, never on framework internals or
on Eloquent models.

- Each module type has a versioned contract: payment gateway, provisioning,
  registrar, notification channel, fraud, tax, report, widget, addon.
- Cross-cutting services are exposed the same way: `AuditRecorder`,
  `StorefrontRenderer` and the permission registry are contracts precisely so
  a module can use them without reaching into implementations.
- A module declares a manifest: name, slug, type, version, compatible
  platform range, entrypoint, permissions and dependencies. The compatible
  range is checked on install and on upgrade.
- Lifecycle is install, enable, disable, upgrade, guarded uninstall. Uninstall
  refuses while data it owns is still referenced.
- Modules contribute permissions through the same registry as core, and a
  permission whose module is removed is orphaned rather than deleted.
- Module UI extension points never bypass core authorization or audit.

## Consequences

- Core may refactor freely behind a contract; changing a contract requires a
  new contract version and a deprecation period.
- Phase 0 establishes the boundary by shipping the first contracts and the
  `modules/` PSR-4 root. The lifecycle and manifest machinery arrive in
  Phase 12.
