# 0008 — Redis and Horizon as the queue baseline

Status: accepted
Date: 2026-09-22

## Context

Provisioning, registrar calls, invoice generation, notifications and webhook
delivery are all slow, failure-prone side effects that must not run inside a
web request. They need retries with backoff, visibility into what is stuck,
and a way to move a permanently failed operation to manual intervention.

## Decision

- Redis is the queue, cache, session and lock backend. It is already required
  for the cache, so this adds no new operational dependency.
- Horizon supervises workers and provides failed-job visibility and metrics.
  Access to its dashboard is gated on the `platform.queue.view` permission,
  not merely on being authenticated, because the dashboard exposes job
  payloads.
- Workers run in their own container, separate from web traffic, so a worker
  restart never drops a request.
- The scheduler runs as a single `schedule:work` process with `onOneServer()`
  on anything that must not run twice.
- Every queued operation that talks to an external system must be idempotent
  and must not hold a database transaction open across the remote call.

Horizon 5.50 requires `ext-pcntl`, which does not exist on Windows. The
composer platform config declares `ext-pcntl` and `ext-posix` as present so
that dependency resolution matches the Linux containers and CI, where they
genuinely are. Horizon's supervisor is therefore only exercised inside a
container — which is where it runs in every deployment anyway.

## Consequences

- Redis becomes a hard dependency of the installation. The health endpoint
  reports it.
- The platform declaration means a genuinely missing `pcntl` in production
  would not be caught by `composer install`. The Phase 17 environment check
  must verify extensions at runtime.
