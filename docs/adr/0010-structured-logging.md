# 0010 — Structured JSON logging with redaction

Status: accepted
Date: 2026-09-22

## Context

Production incidents are diagnosed by querying logs, not by reading them.
Free-text lines cannot be filtered by correlation identifier, organization or
error code. At the same time, the platform handles gateway secrets, panel
credentials and registrar API keys, and a single careless `Log::info($payload)`
would write one of those to disk and then to a log aggregator.

## Decision

- Logs are single-line JSON when `LOG_STRUCTURED=true`, which is the expected
  setting in every deployed environment. Development keeps human-readable
  lines.
- `SecretRedactor` runs as a Monolog processor on every handler, not as a
  convention. It matches key *fragments* case-insensitively, so `password`,
  `password_confirmation`, `db_password` and `oldPassword` are all caught
  without an exhaustive list, and it walks nested structures to a bounded
  depth.
- A card-shaped value is redacted regardless of its key. Card data must never
  reach the platform in the first place; this is a safety net, not a feature.
- The same redactor is used by the audit trail, so the two cannot drift.

## Consequences

- A field genuinely named `token_count` would be redacted. Fragment matching
  trades a small amount of precision for a guarantee, which is the right side
  of that trade for credentials.
- The pattern list lives in `config/platform.php` and removing an entry
  requires an ADR.
