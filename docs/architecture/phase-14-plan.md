# Phase 14 — Licensing Control Plane Plan

Status: complete — see `phase-14-result.md`
Date: 2026-09-24
Previous: `phase-13-result.md`
Handoff: §9 (Separate licensing platform), §15 (Feature flags vs entitlements),
§19 (Licensing Control Plane V2), §22 Phase 14
ADR: `0013-licensing-control-plane-separation.md`

---

## 1. What this phase is, and what it is not

ADR 0013 is already accepted and it decides the shape: **licensing is a
separate application with a separate database, deployed by the vendor.** A
customer installation talks to it over HTTPS and holds no authority of its own.

So this phase builds **the installation side**. That is not a reduction of
scope; it is the whole of the scope that belongs in this repository. Building
the vendor's License API inside the product would put the signing authority
inside the installation it is meant to license, which is the one thing ADR
0013 exists to prevent.

What ships here:

- the persistent installation identity and the environment claims sent with it;
- the signed-token format and **local** verification with an embedded public
  key;
- the client — activate, heartbeat, deactivate, validate — with a timeout,
  bounded retries, a correlation ID and sanitised errors;
- the cached licence state, the grace period, and what happens when grace runs
  out;
- `Entitlements` bound to the token, replacing the unrestricted default when
  and only when a licence is configured;
- the heartbeat as an automation task, idempotent and asking about state rather
  than about the clock;
- the admin Licence screen and the health check;
- the HTTP contract the vendor's side must implement, written down.

What does not ship here, and why:

- **The vendor's License API and its admin.** A separate deployment (ADR 0013,
  handoff §9). What this repository gets instead is `docs/licensing/api.md` —
  the request and response of every endpoint, the token claims, the signature
  algorithm — and a fake client so the installation side is fully testable
  without it. The same shape the provider contracts have.
- **The update/release service** (handoff §18). It is named in Phase 14's line
  as "update entitlement", and the *entitlement* ships here: a token can carry
  `updates.priority` and the installation can answer whether it is allowed one.
  Downloading and applying a signed release manifest is Phase 17's business,
  next to backup readiness and the maintenance strategy it needs.

## 2. The decisions

**The installation UUID lives in `platform_state`, not the cache and not the
environment.** It must survive a Redis flush, a deploy and a config cache, and
it must *not* survive a database restore into a second environment being
noticed — which it will be, because the licence server sees two installations
claiming one ID and that is exactly the anomaly it should see.

**The token is verified locally, always.** `sodium_crypto_sign_verify_detached`
with an Ed25519 public key shipped in the distribution. The installation can
prove a licence is valid; it cannot mint one. A token that fails verification
is not a licence, no matter which server it came from.

**Grace is generous and its end is not an outage.** A temporary licence-server
outage must never take a customer's production system down. So:

| State | What the installation does |
| --- | --- |
| Valid token, within its heartbeat deadline | the token's entitlements |
| Server unreachable, inside grace | the last known entitlements, unchanged |
| Grace exhausted, or licence revoked | the **unlicensed** entitlements |
| No licence configured at all | everything allowed |

"Unlicensed" is deliberately not "nothing works". It is the set a self-hosted
installation with no commercial relationship gets: the platform runs, and the
vendor mark comes back. A gate whose exhausted state is an outage is a gate
that will one day take down a customer over a DNS failure, and then nobody
will trust the licence check again.

The last row is the existing dull default (`UnrestrictedEntitlements`) and it
stays. An installation nobody licensed must not be crippled by a check it has
no way to answer.

**Entitlements stay a single question.** `Entitlements::allows($feature)`,
already the contract since Phase 11. Nothing in this codebase asks what
edition it is. The token carries an edition because the vendor's commercial
model needs one; the product never reads it except to print it on the licence
screen.

**A feature the token does not mention is allowed, not denied.** The token
lists what a licence *excludes* as well as the limits it sets, because the
alternative — an allow-list — means every feature added to the product after a
token was minted is silently switched off for every existing licence. That is
a release that breaks paying customers and nobody finds out until they call.

**Numeric limits are entitlements too.** `max_staff_users` and
`max_reseller_accounts` are in the handoff's list. They are answered by the
same service through a second method, `limit()`, rather than by string-parsing
`allows('max_staff_users:5')`.

**The heartbeat is a run, and time is not a trigger** (ADR 0031). The task
asks "is the licence state older than its heartbeat interval", never "is it
Tuesday", so a scheduler that was down for three days catches up. It is
wrapped in `RecordedRun` like every other task, and running it twice changes
nothing the second time.

**Replay and clock anomalies are audited, not corrected.** A token issued in
the future, or one whose `issued_at` is older than the token already held, is
refused and written to the audit trail. An installation that silently accepted
a replayed token would be an installation somebody could pin to a revoked
licence with a captured response.

**Nothing licensing-related is ever logged in full.** The licence key is a
secret; `SecretRedactor` already knows to redact `license_key`, and the tests
assert the token itself never reaches the log or the health check.

## 3. Slices

1. **Identity and the token.** Installation UUID, environment claims,
   `LicenceToken`, `VerifyLicenceToken`, the public key seam.
2. **The client and the state.** `LicenceClient` contract, HTTP
   implementation, `LicenceState` in `platform_state`, activate / heartbeat /
   deactivate, grace arithmetic.
3. **Entitlements from the token**, replacing the default only when a licence
   is configured, plus `limit()`.
4. **The heartbeat task**, the health check, and the admin screen.
5. **The contract document** for the vendor's side.

## 4. Not in this phase

- The vendor's License API application, its database and its admin.
- Downloading or applying releases (Phase 17).
- Hardware or IP-based constraints. The handoff allows them "if commercially
  necessary" and they are the constraint most likely to lock a paying customer
  out of their own system after a server migration, so they are not built
  speculatively.
- Obfuscation. ADR 0013: it is explicitly not the trust boundary.
