# The licence API, as this installation speaks it

This document is the contract for a system **this repository does not
contain**. The licensing control plane is a separate application with a
separate database, deployed by the vendor (ADR 0013, handoff §9): if the
licensing authority lives inside the installation it is meant to license,
there is no licensing authority.

So what follows is what the installation sends and what it will accept. A
vendor implementing the other half has to satisfy exactly this and nothing
more.

---

## The trust model, in one paragraph

The installation holds an Ed25519 **public** key, shipped in its distribution.
The vendor holds the private half, and it never leaves vendor infrastructure.
Every answer that grants anything is a signed token. The installation can
therefore *prove* a licence is valid and *cannot mint one* — which is the only
security property here worth anything, and it survives the customer having the
source, the database and the configuration.

Source obfuscation is explicitly not part of this. Distributed PHP is readable.

## Transport

- HTTPS. A plain-HTTP licence server is a licence server anybody on the path
  can impersonate — although the signature means impersonating it grants
  nothing, which is the point of the design.
- `Content-Type: application/json`, `Accept: application/json`.
- `X-Correlation-Id` on every request. The installation generates it; a vendor
  debugging an activation failure and an operator reading their own log are then
  looking at the same string.
- The installation uses a **10 second timeout and two retries**. A licence
  server that needs longer than ten seconds will be treated as unreachable,
  which means the customer keeps working and the vendor sees a missing
  heartbeat. That is the correct outcome and it is not negotiable from the
  vendor's side.

## Endpoints

### `POST /v1/licenses/activate`

```json
{
  "license_key": "LIC-XXXX-XXXX",
  "installation_id": "9f1c…",
  "claims": { "host": "panel.example.com", "version": "1.4.2", "php": "8.4" }
}
```

`claims` is everything the installation discloses, and it is deliberately dull:
the host the panel answers on, the product version, the PHP major.minor. No
paths, no database name, no keys. A vendor that needs more than this should ask
for it in a contract, not take it from a program.

**200** `{ "token": "<wire form>" }`

Any other status is treated as *unreachable*, not as a refusal. This is the
single most important line in this document: **"the vendor is broken" and "your
licence is revoked" must never be the same outcome.** A 500 must not disable a
customer's production system, and neither must a 403 — if a licence is refused,
say so in a signed token whose `status` is `revoked`, and the installation will
apply it.

### `POST /v1/licenses/heartbeat`

The same body, the same answer. Called when the installation's stored state
says the heartbeat deadline is half way past — so roughly twice per heartbeat
window, not on a fixed schedule.

### `POST /v1/licenses/deactivate`

```json
{ "license_key": "LIC-XXXX-XXXX", "installation_id": "9f1c…" }
```

**2xx** with any body. The installation clears its local state **whether or not
this call succeeds**: an operator who has decided the installation is no longer
licensed must not be blocked by the vendor being down. An activation the server
still believes is live is the vendor's to reconcile, and they can see the
missing heartbeats.

### `GET /v1/licenses/entitlements` and `GET /v1/releases/latest`

Named in the handoff and **not called by this installation**. Entitlements
arrive inside the token, which is the only form the installation can verify —
a separate unsigned endpoint listing entitlements would be an endpoint an
attacker could answer. Releases are Phase 17's business.

## The token

Wire form:

```text
base64url(payload) "." base64url(signature)
```

- No padding. `-` and `_` for `+` and `/`.
- The signature is `sodium_crypto_sign_detached(payload, secretKey)` over the
  **raw payload bytes**, not over re-encoded JSON. Two JSON encoders disagree
  about key order and whitespace, and a signature over re-encoded JSON is a
  signature that fails on a different PHP version.
- No algorithm field. A token that says which algorithm to use is a token that
  can say `none`.

Payload:

```json
{
  "licence_id": "lic_01H…",
  "installation_id": "9f1c…",
  "edition": "enterprise",
  "status": "active",
  "excluded": ["reseller.white_label", "advanced_reports"],
  "limits": { "max_staff_users": 25, "max_reseller_accounts": 10 },
  "issued_at": "2026-09-24T10:00:00+00:00",
  "expires_at": "2027-09-24T10:00:00+00:00",
  "heartbeat_by": "2026-10-01T10:00:00+00:00"
}
```

| Field | Rule |
| --- | --- |
| `installation_id` | Must equal the id in the request. A mismatch is refused. |
| `status` | `active`, `suspended`, `revoked`, `expired`. Anything unrecognised is read as `revoked` — an unknown status is not a reason to grant anything. |
| `excluded` | What this licence **does not** include. |
| `limits` | Integers. Absent means no ceiling, which is not the same as zero. |
| `issued_at` | Refused if more than 60 seconds in the future, or **older than a token this installation already holds**. |
| `expires_at` | The licence's own end. No outage extends it. |
| `heartbeat_by` | When to come back. Grace is counted from here. |

### `excluded`, not `included`

The token lists what a licence does **not** get. An allow-list would mean every
feature added to the product after a token was minted is silently switched off
for every existing licence — a release that breaks paying customers, and nobody
finds out until they telephone.

### The replay check

A captured response from before a downgrade or a revocation is correctly
signed, unexpired and correctly addressed. The only thing wrong with it is that
the installation has already seen a newer one, so `issued_at` must move
forward on every token. A vendor that reissues an identical token with an
unchanged `issued_at` will find heartbeats refused and audited.

## Grace, and what a lapse costs

| State | What the installation does |
| --- | --- |
| Valid token, inside its heartbeat window | the token's entitlements |
| Server unreachable, inside grace | the last known entitlements, unchanged |
| Grace exhausted, or `suspended` / `revoked` / `expired` | the unlicensed set |
| No licence ever activated | everything allowed |

Grace is counted **from `heartbeat_by`**, not from the last contact. A vendor
who says "come back in seven days" and a default grace of thirty means a
customer keeps working for thirty-seven. That is the promise which makes the
whole arrangement safe to ship, and `LICENSE_GRACE_DAYS` is the operator's to
lengthen.

The unlicensed set is **not "nothing works"**. Today it means one thing: the
vendor mark comes back. No screen closes, no order is refused, no service is
suspended. A gate whose exhausted state is an outage is a gate that will one
day take a customer down over a DNS failure, and after that nobody trusts the
licence check again.

## What is audited on the installation

Every one of these writes an audit row, and the reason says which it was —
"licence invalid" tells an incident review nothing:

- `licensing.activated`, `licensing.heartbeat`, `licensing.deactivated`
- `licensing.heartbeat.failed` — the vendor could not be reached
- `licensing.deactivate.unreachable`
- `licensing.token.refused` — with the specific refusal: bad signature, wrong
  installation, dated in the future, replayed, malformed, or no public key

The licence key appears in none of them. It is in the redaction list under both
spellings, and a test asserts it never reaches an audit row.

## Generating a key pair

```php
$pair = sodium_crypto_sign_keypair();

// Ships with the product, in the file LICENSE_PUBLIC_KEY_PATH points at.
echo base64_encode(sodium_crypto_sign_publickey($pair)), PHP_EOL;

// Never leaves the vendor's licensing infrastructure.
echo base64_encode(sodium_crypto_sign_secretkey($pair)), PHP_EOL;
```

The installation accepts the public key as base64 or as 32 raw bytes, because
an installer that wrote it either way should work.
