# 0047 — A package is verified before it touches disk

Status: accepted
Date: 2026-09-24
Extends: [0038](0038-a-module-may-execute.md), [0041](0041-a-lapsed-licence-is-not-an-outage.md)

## Context

The marketplace downloads code from the internet and puts it where
`ModuleLoader` will later execute it. That is the most dangerous thing this
product does, and it is worth being precise about where the danger actually is.

ADR 0038 established that **enabling** is the moment somebody else's code runs,
and that installing must therefore run nothing. That reasoning holds for a
package an operator copied into `modules/` themselves: they chose the bytes.
With a download, they did not — they chose a *name in a catalogue*, and
something else chose the bytes.

So the question ADR 0038 did not have to answer is: between "an operator pressed
Install" and "a row exists", what has already happened to this machine?

Three things, and every one of them is attack surface before any module class is
loaded:

- **Bytes were written**, somewhere, by a response body this installation did not
  author.
- **An archive was unpacked**, and an archive entry is a *path* the archive
  chooses. `../../../.env` is a valid entry name.
- **A manifest was read**, and the manifest says which slug this is — so a
  package can claim to be a module that already exists.

None of that is "running the module", and all of it is running *on* the module's
terms.

## Decision

**Nothing is unpacked until the archive has been proven, and the proof is over
the archive's own bytes.**

`FetchPackage` downloads to a temporary file, and that file is inert: it is never
added to a path, never read as a manifest, never passed to a zip reader. Then, in
order:

1. **Size.** A response larger than the configured ceiling is refused before it
   is fully read. A download with no bound is a way to fill a disk.
2. **Digest.** SHA-256 of the file must equal the digest the catalogue gave. This
   catches corruption and a truncated transfer, and it is not a security control
   on its own — the catalogue that named the digest could be the attacker.
3. **Signature.** Ed25519, detached, over the **raw archive bytes**, verified
   against a public key shipped in the distribution. This is the security
   control. The installation can prove a package came from the vendor and
   **cannot mint one**, which is exactly the licence token's property (ADR 0041)
   and exactly the reason that design is reused here.
4. **Only then**, extraction — entry by entry, each resolved path checked to be
   inside the destination, with no symlinks and no absolute entries.
5. **Slug.** The manifest's slug must equal the slug the catalogue offered. A
   package free to name itself could install as `gateway-stripe` and inherit the
   configuration of the real one.

A refusal at any step deletes the temporary file and writes nothing else. There
is no partially-fetched state, because a half-unpacked module directory is
exactly what `ModuleCatalogue` would later read as a module.

**The packaging key is not the licence key.** They prove different things — "this
installation is licensed" and "these bytes are ours" — and one compromise must
not be both. They are separate keypairs and separate configuration.

**Verification is not optional, and there is no flag for it.** An installation
that wants to run unsigned code can still put a directory in `modules/` by hand,
which is a thing a human did deliberately on a machine they control. A *download*
that skipped the signature would be the same act with none of the deliberation,
and a setting for it would be a setting somebody turns on to make an error go
away.

## Consequences

- The vendor must sign every package it publishes, and the signing key is the
  thing whose compromise is worst. It never touches this repository.
- A package cannot be delivered through a CDN that rewrites bytes — no
  recompression, no injected headers in the body. The signature is over what is
  served.
- `modules/` gains a provenance question it did not have: a row now records
  whether a module came from disk or from the marketplace, and the marketplace
  refuses to overwrite a module it did not deliver. Otherwise a catalogue entry
  could replace a hand-installed package with its own.
- An air-gapped installation is unaffected: no marketplace URL means an empty
  catalogue, and copying a directory into `modules/` works exactly as before.
- The refusals are named and separate (`PackageRefused::digest()`,
  `::signature()`, `::unsafePath()`, …). "The download failed" would be one
  message covering a mirror problem and an attack, and those must never look the
  same in an audit log.
