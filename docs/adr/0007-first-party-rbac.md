# 0007 — First-party permission-based RBAC

Status: accepted
Date: 2026-09-22

## Context

The handoff forbids `is_admin`-style authorization outright. Staff need
granular, auditable capabilities, and modules must be able to contribute
permissions of their own. A third-party ACL package would work, but it would
own a security-critical boundary, model roles without the scope separation
resellers require, and tie the platform's authorization semantics to an
external release cycle for the next decade.

## Decision

Authorization is permission-based and first-party.

- Permissions are declared in code by `PermissionRegistry`. The registry is
  framework-free, so the catalogue can be unit tested and diffed between
  releases. Registering a slug twice throws: two features silently sharing a
  capability is exactly the authorization bug that survives review.
- `platform:permissions:sync` mirrors the registry into the `permissions`
  table so roles can reference real foreign keys and the admin UI can list
  them. It is idempotent and runs on every deployment.
- A permission that disappears from code is marked `orphaned_at`, not
  deleted. Deleting would cascade away the role grants that explain
  historical decisions, and reinstalling the module that declared it
  restores the grant.
- Roles carry a scope (`staff` or `customer`). A customer-scoped role cannot
  be assigned to a staff subject, or the reverse, however the data is edited.
- Assignments are polymorphic, so customer contacts and API tokens can carry
  roles in later phases with no schema change.
- Exactly one bypass exists: the `super-admin` system role, via `Gate::before`.
  It is a named role rather than a boolean column, and its use on a high-risk
  capability is audited.
- Sanctum token abilities are intersected with the owner's effective
  permissions, so a token can never exceed its owner.

Effective permissions are cached per subject with a generation counter, so a
change to a role's permission set invalidates every assignee without needing
a taggable cache store.

## Consequences

- Roughly 250 lines of first-party code to maintain, against full control of
  a security boundary and no upgrade coupling.
- Every new feature must declare its permissions in `CorePermissions`, which
  makes the capability surface reviewable in one file.
