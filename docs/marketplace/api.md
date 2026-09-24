# Marketplace API — the contract

The marketplace is **a separate application this repository does not contain**,
exactly as the licence control plane is. This file is what an installation
expects of it; `Tests\Support\FakeMarketplaceClient` is what the tests drive;
`HttpMarketplaceClient` is the implementation, and it has never spoken to a real
vendor.

Related: [ADR 0047](../adr/0047-a-package-is-verified-before-it-touches-disk.md),
[ADR 0038](../adr/0038-a-module-may-execute.md),
[ADR 0041](../adr/0041-a-lapsed-licence-is-not-an-outage.md).

---

## Authentication

Every request carries the installation's licence key as a bearer token, plus:

| Header | Meaning |
| --- | --- |
| `Authorization: Bearer <licence key>` | Which installation is asking. Absent on an unlicensed installation. |
| `X-Correlation-Id` | So the vendor and the operator can read the same string in two logs. |
| `X-Platform-Version` | What the installation is running. |
| `X-Sdk-Version` | Which extension surface it offers (ADR 0039). |

**The catalogue is what this installation may have**, not everything that
exists. The vendor answers with the subset the licence entitles it to, so core
holds no list of paid modules and gates on nothing — a catalogue that does not
list something is not an outage, whereas a local gate whose default is deny
would be.

An installation with no licence key still gets a catalogue: whatever the vendor
offers anonymously.

## `GET /catalogue`

```json
{
  "packages": [
    {
      "slug": "gateway-stripe",
      "name": "Stripe",
      "type": "payment-gateway",
      "version": "1.4.0",
      "summary": "Takes card payments through Stripe.",
      "provider": "InfraCMS",
      "sdk": ">=1.2",
      "download_url": "https://packages.example/gateway-stripe-1.4.0.zip",
      "digest": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
      "signature": "base64url-ed25519-over-the-archive-bytes",
      "size": 184320,
      "dependencies": [],
      "info_url": "https://example/docs/gateway-stripe"
    }
  ]
}
```

Every field except `summary`, `provider`, `sdk`, `size`, `dependencies` and
`info_url` is required; a package missing one of the required fields is **skipped
rather than rejected**, so one malformed entry does not empty a catalogue.

`type` must be a `ModuleType` this platform knows. An unknown type is skipped for
the same reason: a newer vendor offering a kind of module this installation has
no seam for is not an error, it is a package this installation cannot use.

**`digest` and `signature` are part of the offer, not of the download.** A
response body must never also be the thing that says what the response body
should have been.

## `GET <download_url>`

Returns the package archive, a zip, with no redirect. The response is streamed
to disk and bounded by `platform.marketplace.max_bytes`.

**The signature is over the bytes as served.** A CDN that recompresses a
response is serving different bytes, and different bytes are not signed.

## What the installation does with the answer

In this order, and nothing is skipped (ADR 0047):

1. **Size** — a response over the ceiling is refused while it is still arriving.
2. **Digest** — SHA-256 of the file against `digest`. Catches a truncated
   transfer. Not a security control: the catalogue that named it could be the
   attacker.
3. **Signature** — Ed25519, detached, over the raw archive bytes, against the
   packaging public key shipped in the distribution. This is the security
   control.
4. **Unpack** — entry by entry, every path checked to land inside the
   destination, into a staging directory that is moved into place only when
   every entry has been written.
5. **Slug** — the manifest's slug must equal the slug the catalogue offered.

Then, and only then, `InstallModule` writes a row. **Nothing has executed.**
Enabling is separate and remains the one moment an operator agrees to run
somebody else's code.

## The packaging key

A separate Ed25519 keypair from the licence key. They prove different things —
"this installation is licensed" and "these bytes are ours" — and one compromise
must not be both.

The public half ships in the distribution at
`platform.marketplace.public_key_path`, as raw bytes or base64url. The private
half never touches this repository.

With no key configured, **every download is refused**. That is the honest answer
rather than a flag that turns the check off; a module can still be placed in
`modules/` by hand, which is a deliberate act on a machine somebody controls.

## What this API is not

- **Not a checkout.** Buying a module is a transaction with the vendor. This
  platform is not growing a second billing system pointed at itself; `info_url`
  is where an operator goes, and the licence is what changes afterwards.
- **Not an updater.** The catalogue says a newer version exists. Upgrading is a
  button somebody presses, because a module that upgraded itself would run new
  code nobody agreed to run.
- **Not a publisher.** Only the vendor signs, in this first release.
  Third-party publishing needs a review pipeline, a revocation story and a
  disclosure process, and offering it without them would be offering a promise
  this product cannot keep.
