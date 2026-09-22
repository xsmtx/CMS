# 0006 — Append-only audit log with redaction

Status: accepted
Date: 2026-09-22

## Context

The platform suspends services, refunds money, rotates server credentials and
terminates accounts. When a customer disputes one of those, the answer must
be reconstructable months later and must be trustworthy. A log that the
application can rewrite is evidence of nothing.

## Decision

- `audit_logs` is append-only. The model refuses `updating` and `deleting`.
  Retention pruning, if an operator enables it, is a separate maintenance
  command that is itself audited.
- No `updated_at` column exists, because there is no update path.
- Actor and target are stored as denormalised `type`/`id`/`label` triples
  rather than foreign keys, so a record stays legible after the subject it
  refers to is renamed, anonymised or deleted.
- Writes are synchronous. A queued audit record can be lost when the queue is
  down, and losing the record of a refund is worse than a few milliseconds of
  latency.
- Every payload passes through `SecretRedactor` before it is stored, so a
  password, token or card-shaped value cannot reach the table even if a
  caller passes one.
- Rows carry the organization boundary; installation-level events carry none
  and are visible only to an explicitly unscoped query.
- The public API is the `Audit` facade over the `AuditRecorder` contract, so
  an installation can mirror records to an external SIEM without touching
  feature code, and tests can swap in `FakeAuditRecorder`.

## Consequences

- Audit writes add a row to every sensitive action. Indexes are chosen for
  the three real access patterns: by target, by actor, by action.
- A super-admin bypassing a high-risk permission is audited once per request,
  not once per check: a page render performs dozens of checks and the noise
  would bury the signal.
