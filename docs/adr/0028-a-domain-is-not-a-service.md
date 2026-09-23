# 0028 — A domain is not a service, and silence is not availability

Status: accepted
Date: 2026-09-27

## Context

A domain and a hosting service look alike from a distance. Both are bought,
both expire, both have a provider behind a contract, and both need the
idempotent, failure-is-a-state treatment
([ADR 0026](0026-provisioning-is-idempotent-and-failure-is-a-state.md)).
Merging them would save a table and a contract.

Separately, a domain search has an answer that a control panel never gives:
*I do not know*. A registry times out, an API key is rejected, a TLD's whois
server is down. The tempting simplification is two-valued — available or
not — and the tempting default for a timeout is "available", because that
is the answer that lets the customer carry on.

## Decision

**A domain is its own thing.** Separate table, separate lifecycle, separate
contract. The differences are not cosmetic:

| | Service | Domain |
| --- | --- | --- |
| Placed on | a node | nothing |
| Turned off by | suspend / unsuspend | lock, transfer, redemption |
| Term | a billing cycle | a whole number of years, capped by the registry |
| Owned by | whoever bought it | a **registrant** the registry records |

What they share is the *shape* of calling an adapter, and that shape is
already written down. `RegistrarResult` even reuses `OperationOutcome`
rather than defining a second three-valued enum, so "already done" means
one thing in this codebase.

**Availability is three-valued.** `AvailabilityResult` is available, taken,
or **unknown**, and every layer preserves the third: the adapter, the cache,
the offer, the controller and the page. A customer told "we could not check"
tries again; one told "available" pays for a name somebody else owns, the
registration fails, and a person has to explain it.

**The registrant is not stored here.** It is built from the customer's own
contact and address at the moment of registration and sent to the registry,
which holds the authoritative copy. A second copy in this database would
drift the first time somebody corrected an address on one side only, and
"who owns this domain" would have two answers.

**The EPP transfer code is never stored.** It is fetched when a customer
asks, shown once, and recorded in the event log only as *somebody asked* —
never as its value. It is the credential that moves a domain away from this
platform; a stored copy is a copy somebody has to protect forever, for a
string that is single-use by design.

**A TLD's terms are the registry's rules, not a preference.** `min_years`
and `max_years` are checked before a customer pays, because a registry's
refusal arrives days later as a numeric code.

## Consequences

- `domains.name` is unique per organization. Two customers ordering the
  same name in the same minute both get an order and only the first gets
  the domain; the second is left for an operator, which is the honest
  outcome of a race nobody can win twice.
- A domain line whose extension is no longer sold still becomes a domain —
  it was paid for — with no registrar, so nothing registers it
  automatically and an operator decides.
- Availability is cached for a minute. A search box asks the same question
  five times in that minute; a name that was free an hour ago is not
  evidence of anything.
- Availability is **not** re-checked when the line goes in the cart. It
  would cost another registry call per click and still be stale by
  checkout. The registration is the only authority, which is why it can
  fail and why failure is a state.
- `DomainName` grew a second parser. `parse()` splits at the first dot and
  is right for a hostname; `parseWithin()` splits against the extensions on
  sale and is the only correct one when the TLD is being priced, because
  `co.uk` is two labels and `uk` is one.
