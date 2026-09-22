# 0003 — ULIDs as aggregate identifiers

Status: accepted
Date: 2026-09-22

## Context

Identifiers appear in URLs, API responses, invoices, webhook payloads and
support conversations. Auto-incrementing integers leak business volume — a
competitor reading `/invoices/5821` learns how many invoices exist — and
invite enumeration. Random UUIDv4 primary keys fix that but scatter B-tree
inserts, which degrades write throughput and index locality on tables that
only ever grow.

## Decision

Core aggregate tables use a ULID `char(26)` primary key, exposed directly as
the public identifier: `organizations`, `users`, `roles`, `permissions`,
`audit_logs` and every aggregate added in later phases.

Exceptions, each deliberate:

- Pure pivot tables use a composite primary key of the two foreign keys.
- High-volume append tables that are never referenced by a public URL may use
  a bigint surrogate; `role_assignments` does.
- Framework-owned tables (`jobs`, `cache`, `sessions`,
  `personal_access_tokens`) keep their shipped shape, with morph columns
  widened to ULIDs where they point at our models.

## Consequences

- Identifiers sort by creation time, so range scans stay sequential and
  "recent rows" queries remain cheap.
- No separate public/internal identifier mapping is needed anywhere.
- Keys are 26 bytes rather than 8, which costs index size. Accepted.
- Records generated on different installations never collide, which the
  import/migration framework depends on.
