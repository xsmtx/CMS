# 0017 — First-party authentication, not Fortify

Status: accepted
Date: 2026-09-23

## Context

Laravel Fortify implements sign-in, password reset and two-factor
authentication. Using it would be the default choice, and it is the right
choice for most applications.

This one needs two guards with separate password brokers, an organization
boundary established at the moment of sign-in, failures recorded as
deliberately as successes, and a two-factor challenge that holds a verified
sign-in without granting a session. Fortify assumes a single guard, owns its
own routes, and exposes its pipeline through action bindings rather than
through the flow itself.

## Decision

Build on Laravel's native `Auth`. The result is roughly two hundred lines of
controllers plus an `AuthenticateUser` service, which is less than the wiring
Fortify would have needed around it.

The decisions that make this more than a reimplementation:

- **One place grants a session.** `AuthenticateUser::complete()` is the only
  code that logs anyone in, from any path. Session regeneration, login
  history, the session registry, the organization boundary and the audit
  record are therefore impossible to forget, because no caller does them.
- **Every failure looks the same.** One message for an unknown address and a
  wrong password alike. A miss still runs a hash comparison, because response
  time would otherwise answer the question the message refuses to. The real
  reason goes to the login history, where an operator can tell a forgotten
  password from a credential-stuffing run.
- **Two throttle buckets.** Per identity, which stops an attacker grinding
  one password, and per address, which stops one host spraying a common
  password across many accounts. The second catches what the first cannot
  see. A success clears the identity bucket but not the address one.
- **The second factor sits between credentials and a session.** The pending
  sign-in holds only an identifier in the session, so there is no
  half-authenticated state for the rest of the application to misread, and a
  stolen session at that point is worth nothing without the code.
- **Sessions live in Redis, mirrored to a table.** Redis cannot answer "which
  devices am I signed in on"; the mirror does, and revocation goes back
  through the session driver's own handler, so the feature works whichever
  driver an installation configures and a stolen cookie stops working
  immediately rather than at expiry.

Two-factor uses `pragmarx/google2fa` with `bacon/bacon-qr-code`. The QR code
is rendered server side, because a third-party image endpoint would receive
the shared secret. Recovery codes are hashed like passwords and removed on
use rather than marked, so a database disclosure hands over nothing and a
replay cannot succeed. Enrolment is three steps — generate, confirm with a
live code, activate — because a secret that is stored but never confirmed
protects nothing and locks out anyone whose authenticator silently failed.

## Consequences

- No upstream fixes arrive for free; the flows are ours to maintain.
- The whole flow is readable in one place, which is what made the
  organization boundary at sign-in and the failure-reason recording possible
  without fighting anything.
- Revisit if Fortify grows first-class multi-guard support and the surface
  here has not diverged further.
