# 0041 — A lapsed licence is not an outage

Status: accepted
Date: 2026-09-24
Extends: `0013-licensing-control-plane-separation.md`

## Context

ADR 0013 decided that licensing is a separate system and that the installation
verifies a signed token locally. It did not decide what happens when the answer
is "no" — and that question turns out to be the one that determines whether the
licence check is trustworthy at all.

The tempting design is the strict one: no valid licence, no product. It is also
the design that eventually takes a paying customer's production system down
over somebody else's DNS failure, a TLS certificate that expired on the
vendor's load balancer, or a firewall rule added on a Friday. When that happens
once, the operator's conclusion is not "our licence lapsed"; it is "this
software breaks by itself". After that nobody trusts any part of the licence
machinery, and the first thing a competent operator does is find the check and
disable it.

Three sub-decisions follow from taking that seriously.

## Decision

### 1. The unlicensed set is small, and the platform keeps running

When a licence is suspended, revoked, expired, or out of contact for longer
than the grace period, entitlements fall back to the **unlicensed set**. Today
that means exactly one thing: the vendor mark returns.

No screen closes. No order is refused. No service is suspended. No customer of
the customer notices. The commercial pressure comes from the vendor mark being
visible to the operator's own clients, which is real pressure and costs nobody
their business.

An entitlement that gates something operationally essential may not be added
without revisiting this ADR, because that is the moment the trade-off changes.

### 2. Grace is counted from the heartbeat deadline, not from last contact

A token says when to come back (`heartbeat_by`). Grace is added to *that*, so a
vendor who asks for a weekly heartbeat and a default grace of thirty days gives
an installation thirty-seven days of silence before anything changes.

Counting from last contact would make the grace period shorter exactly when it
matters — during a long outage, when the last contact is already old.

`LICENSE_GRACE_DAYS` is the operator's to lengthen. It is deliberately not the
vendor's to shorten below what the token implies.

### 3. The token lists exclusions, not inclusions

A token carries `excluded` — the features this licence does **not** get — and
numeric `limits`. A feature the token does not mention is allowed.

An allow-list is the obvious alternative and it is wrong: every feature added
to the product after a token was minted would be silently switched off for
every existing licence. The release notes would say "new feature", the customer
would not have it, and nobody would find out until they telephoned. With an
exclusion list, a new feature arrives switched on and the vendor adds it to the
exclusions of the editions that should not have it — which is a deliberate act,
at the moment the commercial decision is actually made.

### 4. "Unreachable" and "refused" are different types

`LicenceUnreachable` and `LicenceRefused` are separate exceptions and nothing
catches them together except a screen that reports both. A client that
collapsed them would be a client that turns a vendor outage into a revocation.

A refused token is a security event: it is audited with *which* refusal it was —
bad signature, wrong installation, dated in the future, replayed, malformed —
and it never repairs itself by falling back to the last good token. An
installation that did that could be pinned to a revoked licence by anybody able
to replay one captured response.

## Consequences

- The licence check cannot be used to enforce payment by withholding the
  product. That is a commercial constraint accepted knowingly: the vendor's
  levers are the visible mark, the contract, and refusing updates.
- A customer can run unlicensed indefinitely with the mark showing. This is
  also how the product is evaluated, so it is a feature as often as it is a
  loss.
- `Entitlements::limit()` exists alongside `allows()`. Numeric ceilings are
  answered as numbers, and `null` means "no ceiling" — never zero, which is a
  real answer.
- The health check reports three states and `degraded` is the one that matters:
  it is the only warning an operator gets between losing contact and losing the
  mark.
