# Architecture Decision Records

Each file records one decision: the context that forced it, the decision
itself, and the consequences we accept. An ADR is never edited once accepted —
a later decision supersedes it with a new record and both stay in the history.

Status values: `proposed`, `accepted`, `superseded by NNNN`, `deprecated`.

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-modular-monolith.md) | Modular monolith with four layers | accepted |
| [0002](0002-organization-ownership.md) | Organization ownership boundary | accepted |
| [0003](0003-ulid-identifiers.md) | ULIDs as aggregate identifiers | accepted |
| [0004](0004-error-envelope.md) | Stable JSON error envelope | accepted |
| [0005](0005-correlation-ids.md) | Correlation IDs via Laravel Context | accepted |
| [0006](0006-append-only-audit-log.md) | Append-only audit log with redaction | accepted |
| [0007](0007-first-party-rbac.md) | First-party permission-based RBAC | accepted |
| [0008](0008-queues-and-scheduling.md) | Redis and Horizon as the queue baseline | accepted |
| [0009](0009-frontend-and-renderer-abstraction.md) | Inertia for admin/client, renderer abstraction for storefront | accepted |
| [0010](0010-structured-logging.md) | Structured JSON logging with redaction | accepted |
| [0011](0011-quality-gates.md) | Quality gates and architecture tests | accepted |
| [0012](0012-docker-development-environment.md) | Docker Compose development environment | accepted |
| [0013](0013-licensing-control-plane-separation.md) | Licensing control plane is a separate system | accepted |
| [0014](0014-money-representation.md) | Money as integer minor units | accepted |
| [0015](0015-module-sdk-boundary.md) | Module SDK boundary | accepted |
