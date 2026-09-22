# 0012 — Docker Compose development environment

Status: accepted
Date: 2026-09-22

## Context

The platform is sold to operators who run it themselves, often on a single
server. Requiring Kubernetes, or any orchestration layer, to install it would
disqualify most of the market. Development, meanwhile, needs MariaDB, Redis,
an SMTP sink and S3-compatible storage without each developer installing four
services by hand.

## Decision

- `compose.yaml` defines the development environment: PHP-FPM, Nginx,
  MariaDB 11.8, Redis, a Horizon worker, a scheduler, Mailpit and MinIO.
- Kubernetes is never required. A production install is Nginx plus PHP-FPM
  plus MariaDB plus Redis; nothing in the code assumes more.
- The PHP image is multi-stage: `development` runs as the host user so
  bind-mounted files stay writable, `production` bakes dependencies and
  assets in and disables opcache timestamp validation.
- Vite runs on the host rather than in a container. Hot reload over a bind
  mount is materially slower and adds no fidelity.
- MariaDB's init script creates a second `_test` schema, so the test suite
  never shares a database with development data.

## Consequences

- Docker is required for development but not for deployment.
- Compose service names (`db`, `redis`, `mailpit`, `minio`) are the defaults
  in `.env.example`; `.env.testing` uses the forwarded host ports so the
  suite runs identically from the host and in CI.
