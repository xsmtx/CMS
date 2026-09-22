# 0013 — Licensing control plane is a separate system

Status: accepted
Date: 2026-09-22

## Context

The platform is licensed commercially and installed on infrastructure the
vendor does not control. A customer installation is, from the vendor's point
of view, hostile: its database, source and configuration are all readable and
writable by whoever runs it. If the licensing authority lives inside that
installation, there is no licensing authority.

## Decision

Licensing is a separate application with a separate database, deployed by the
vendor. A customer installation talks to it over HTTPS and holds no authority
of its own.

- The installation generates a persistent installation UUID on first boot.
- Activation sends the licence key, installation ID and normalised
  environment claims. The server returns a short-lived token signed with a
  private key that exists only on licensing infrastructure.
- The installation verifies that token locally with an embedded public key.
  It can prove a licence is valid; it cannot mint one.
- Successful validation is cached for a configurable grace period. A
  temporary outage of the licensing service must never take a customer's
  production system down.
- Revocation takes effect on the heartbeat/grace schedule. Replay attempts
  and clock anomalies are audited.

Source obfuscation is explicitly **not** the trust boundary. Distributed PHP
is readable; the security property comes from the private key never leaving
vendor infrastructure.

Entitlements are consumed through a central service
(`Entitlements::allows('reseller.white_label')`), never as scattered
`edition === 'enterprise'` comparisons. Deployment configuration, feature
flags and commercial entitlements stay three separate concepts.

## Consequences

- Phase 0 declares `LICENSE_API_URL`, `LICENSE_KEY` and
  `LICENSE_PUBLIC_KEY_PATH` so deployments are forward-compatible. Nothing
  reads them until Phase 14.
- An offline installation works for the length of the grace period, which is
  a deliberate commercial trade-off.
