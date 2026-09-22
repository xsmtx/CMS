# 0005 — Correlation IDs via Laravel Context

Status: accepted
Date: 2026-09-22

## Context

A single customer action fans out across a web request, several queued jobs,
calls to a payment gateway and a hosting panel, and an outbound webhook.
When it fails, an operator must reconstruct the sequence from logs written by
different processes minutes apart. Without a shared identifier that is an
exercise in timestamp correlation and guesswork.

## Decision

Every unit of work carries a correlation identifier.

- `AssignCorrelationId` runs first in the global middleware stack, so nothing
  can log before the identifier exists.
- An inbound `X-Correlation-Id` is honoured only when
  `CORRELATION_ID_TRUST_INBOUND=true` and the value is a well-formed ULID or
  UUID. Anything else is discarded and a fresh identifier generated: an
  attacker must not be able to poison log aggregation with arbitrary strings,
  including newlines.
- The value is stored in Laravel's `Context`, which serialises into queued
  jobs automatically and is appended to every log record. No per-call
  plumbing is needed.
- The response returns it in the same header, the error envelope carries it
  as `request_id`, outbound HTTP attaches it, and each scheduled task run
  gets a fresh one.

## Consequences

- One string, quoted by a customer, retrieves the whole story: request log,
  job logs, provider call and audit rows.
- Trusting the inbound header is a deployment decision, safe only behind a
  proxy that always sets it.
- `automation_runs` in Phase 9 reuses the scheduled-task identifier rather
  than inventing its own.
