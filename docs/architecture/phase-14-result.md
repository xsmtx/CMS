# Phase 14 — Licensing Control Plane Result

Status: complete
Date: 2026-09-24
Plan: `phase-14-plan.md`
Handoff: §9, §15, §19, §22 Phase 14
ADR: `0013-licensing-control-plane-separation.md`,
`0041-a-lapsed-licence-is-not-an-outage.md`

---

## 1. What shipped

The installation side of the licensing control plane, in full.

- **Identity.** `InstallationIdentity` — one UUID in `platform_state`, and the
  three dull environment claims sent with it.
- **The token.** `LicenceToken`, `LicenceStatus`, and `VerifyLicenceToken`:
  Ed25519 through libsodium, verified against a public key shipped in the
  distribution.
- **The client.** `LicenceClient` in `Domain`, `HttpLicenceClient` in
  `Infrastructure` with a ten-second timeout, two retries, a correlation ID and
  sanitised errors, and `UnconfiguredLicenceClient` for an installation with no
  vendor.
- **The state and grace.** `LicenceState` in `platform_state`, and `Licensing`
  — activate, heartbeat, deactivate.
- **Entitlements.** `LicensedEntitlements`, bound when a licence API is
  configured; `UnrestrictedEntitlements` otherwise, unchanged. `limit()` added
  to the contract.
- **The heartbeat.** `SendLicenceHeartbeat`, a `RecordedRun` task on the
  hourly schedule, asking about state rather than about the clock.
- **The screen.** `/admin/licence`, owner only.
- **The health check.** `LicenceCheck`, three states, reads a row and never
  calls the vendor.
- **The contract.** `docs/licensing/api.md` — what a vendor implementing the
  other half has to satisfy.

## 2. What did not ship, and why that is correct

**The vendor's License API application, its database and its admin.** ADR 0013
is explicit: licensing is a separate application with a separate database,
deployed by the vendor. Building it inside the product would put the signing
authority inside the installation it is meant to license, which is the one
thing that ADR exists to prevent. What this repository has instead is the
documented HTTP contract and a fake client, which is the same shape the
provider integrations have.

**The update/release service.** Phase 14's line in the handoff names "update
entitlement", and the *entitlement* is here — a token can carry
`updates.priority` and `Entitlements::allows()` answers it. Downloading and
applying a signed release manifest needs backup readiness, a maintenance
strategy and a rollback story, all of which are Phase 17's.

**IP and hardware constraints.** The handoff allows them "if commercially
necessary". They are the constraint most likely to lock a paying customer out
of their own system after a server migration, so they are not built
speculatively.

## 3. The decisions

ADR 0041 records the four that are contestable: the unlicensed set is small and
the platform keeps running, grace counts from the heartbeat deadline, the token
lists exclusions rather than inclusions, and "unreachable" is a different type
from "refused". The rest:

**The installation UUID is in the database on purpose.** Not the cache, which
vanishes on a flush and would exhaust an activation limit in a week. Not the
environment file, which somebody copies to staging. In the database, so that
restoring a production backup into a second environment produces exactly the
collision the licence server should see — two installations heartbeating one
identity. An identity that regenerated itself on restore would hide the one
event worth noticing.

**The signature covers the raw payload bytes.** Two JSON encoders disagree
about key order and whitespace, so a signature over re-encoded JSON is a
signature that fails on a different PHP version. The token is
`base64url(payload).base64url(signature)` and there is no algorithm field: a
token that says which algorithm to use is a token that can say `none`.

**The client returns a token, never a verdict.** A client that returned
`valid: true` would be a client somebody could replace with one that always
says so. The transport is untrusted; the signature is the authority.

**A replayed token is refused by comparing `issued_at` against the newest token
already held.** A captured response from before a downgrade is correctly
signed, unexpired and correctly addressed — the only thing wrong with it is
that the installation has seen a newer one.

**Deactivation is local first.** The state is cleared whether or not the server
answered. An operator who has decided this installation is no longer licensed
must not be blocked by the vendor being down, and an activation the server
still believes is live is the vendor's to reconcile from the missing
heartbeats.

**A failed heartbeat is a completed run.** The automation screen shows nothing
red during a vendor outage, because the entitlements did not move and a red row
every hour is a red row nobody reads. A *refused token* does fail the run — it
is a security event rather than weather.

**`lastContactAt` is not updated by a failure.** It means "the last time we
actually got an answer", and moving it would make the licence screen say
everything was fine.

**The licence key is the one secret here.** It is write-only on the screen, in
the redaction list under both spellings, and three tests assert it reaches
neither an audit row, a health report nor the rendered page. The public key is
public by definition and is still never printed: a page that prints key material
teaches an operator that pages print key material.

## 4. Found while building it

- `AppAlert` had no `warning` tone. A grace period is neither information nor
  an error, and drawing it as one of those is how a warning stops being read —
  as info it is ignored, as danger it is panicked over. Added, with `role`
  staying `status` rather than `alert`: interrupting a screen reader to say a
  licence expires in three weeks is not proportionate.
- `Entitlements` grew a second method, so the one anonymous implementation in
  `BrandingTest` had to grow one too. That is the interface doing its job.
- Carbon 3's `diffInSeconds` returns a float, and half a window is a whole
  number of seconds or it is nothing.
- The automation screen's task count is asserted as a number, so adding a task
  failed that test — which is the assertion working: a task the command can run
  and the screen cannot offer would be invisible.

## 5. Gates

Pint, Rector, PHPStan level 8, Pest on MariaDB, ESLint, Prettier, vue-tsc,
Vitest, Vite build — all green. 1203 Pest tests.
