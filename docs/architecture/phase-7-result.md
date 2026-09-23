# Phase 7 — Domains Result

Status: complete
Date: 2026-09-27
Plan: `phase-7-plan.md`
Next phase: Phase 8 (Support + Content + Notifications) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **718 passed, 2489 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 17 passed |
| Build | `vite build` | pass |
| Migrations | 22 migrations on MariaDB 11.8 | clean |

Phase 6 finished at 659 tests; this phase adds 59.

## 2. The decision this phase turns on

**A domain is not a service, and silence is not availability**
([ADR 0028](../adr/0028-a-domain-is-not-a-service.md)).

They look alike — bought, expiring, a provider behind a contract — and
merging them would have saved a table. It would also have been wrong in
every detail that matters: a service is placed on a node and a domain is
placed nowhere; a service is suspended and a domain is locked, transferred
or redeemed; a service's term is a billing cycle and a domain's is a whole
number of years the registry caps; a service belongs to whoever bought it
and a domain belongs to a **registrant** the registry records.

The second half is the one that costs money. A domain search has an answer a
control panel never gives — *I do not know* — and the tempting default for a
registry timeout is "available", because it lets the customer carry on.
`AvailabilityResult` is three-valued and every layer preserves the third:
the adapter, the cache, the offer, the controller and the page. A customer
told "we could not check" tries again. One told "available" pays for a name
somebody else owns.

## 3. Problems found

**I overwrote two existing classes.** `DomainName` and
`InvalidDomainName` were written in Phase 3 and used by `AddToCart`;
without checking, I wrote new ones over them. PHPStan caught it immediately
— `AddToCart` called `parse()` with two arguments and read a `->value` that
no longer existed — and the originals came back out of git.

The fix is better than either version. `parse()` splits at the first dot and
is right for a **hostname**, the name a hosting account is set up for.
`parseWithin()` splits against the extensions actually on sale and is the
only correct one when the TLD is being priced, because `co.uk` is two labels
and `uk` is one. The old docblock claimed to handle `co.uk` and did not.

**Booleans have the same database-default trap as money.** A NOT NULL
column with a default fills the row but leaves the model in memory without
the attribute, and a cast reads that absence as null — which is how
`RegistrationRequest::__construct(): Argument #7 ($autoRenew) must be of
type bool, null given` happens on a freshly created domain. Phase 4 learned
this for money columns; the note in `CLAUDE.md` said "money columns" and
should have said "columns".

**The EPP code was in the wrong place, twice.** The adapter read it from
one node of the response; the test put it in another. Rather than pick one
and be confidently wrong, it now looks in both — and the class says plainly
that Namecheap frequently emails the code to the registrant instead of
returning it, so this path legitimately fails and the customer is told to
check their inbox.

## 4. What was built

### TLD pricing

The price matrix ([ADR 0019](../adr/0019-price-matrix.md)) with different
axes: one row per (tld, action, term, currency), entered by hand, and a
missing row means not sold. Four actions, because a `.com` transfer costs a
year's renewal and a redemption costs many times a registration — an
operator who could type one number would have to choose which of those to
be wrong about. The registrar's own cost sits beside the sale price for
margin reporting and is never shown to a customer.

### The domain lifecycle

Ten states following the registry's rules rather than a friendlier set:
expired, then redemption at a penalty, then gone. `failed` is a place a
domain sits with its reason, exactly as for a service.

### The registrar contract

`DomainRegistrar`: availability, register, transfer, renew, nameservers,
lock, auto-renew, transfer code, sync. Every rule from ADR 0026 carries over
unchanged, and one is added — **the EPP transfer code is never stored**. It
is fetched when asked for, shown once, and the event log records that
somebody asked, never the value.

Two adapters: `ManualRegistrar`, which is honest about not being able to
check availability rather than guessing, and `NamecheapRegistrar` over the
Namecheap API. XML parsed defensively, errors read out of the body because
the status code is always 200, the allow-listed client address sent on every
call, and the whole four-part contact set built once because a registry
rejects the lot over one missing field.

### Search and checkout

A search that answers with what the registry said, including that it did not
answer. A taken name is offered as a transfer where the TLD allows one. A
name this installation already holds is taken instantly, from our own
records, which is right even with the registrar down and costs nothing.

Adding to the cart copies the price from the matrix at that moment, the same
rule as every other line. Availability is deliberately not re-checked: it
would cost a call per click and still be stale by checkout.

### Fulfilment

`OrderPaid` again ([ADR 0027](../adr/0027-contexts-meet-through-events.md)).
Domains subscribe alongside provisioning and neither knows the other exists,
so an order with a hosting plan and a domain produces a service and a domain
from two listeners with no code anywhere that knows both happened.

### Screens

Admin: domains with the three counts an operator opens the screen for, a
detail offering only what the registrar supports, and the TLD price grid.
Client: My Domains, and the three things a domain owner actually does —
point it somewhere, decide whether it renews itself, and take it away.

## 5. Files

```text
app/Domain/Domains/        DomainStatus, DomainAction, DomainOperation,
                           DomainName (extended), RegistrarCapabilities,
                           RegistrarAccount, DomainReference,
                           RegistrationRequest, TransferRequest,
                           RegistrantDetails, AvailabilityResult,
                           RegistrarResult, DomainSyncResult,
                           Contracts/DomainRegistrar
app/Application/Domains/   TldCatalog, CheckDomainAvailability, DomainOffer,
                           AddDomainToCart, CreateDomainsForOrder,
                           RunDomainOperation, TransitionDomain,
                           RecordDomainEvent, SaveTld,
                           Listeners/RegisterOrderedDomains, Exceptions/
app/Infrastructure/        Domains/Models, RegistrarRegistry,
                           Registrars/{Manual,Namecheap},
                           Jobs/{RegisterDomain,RunDomainAction}
app/Http/                  Controllers/Admin/{Domain,Tld},
                           Controllers/Client/DomainController,
                           Controllers/StorefrontDomainController,
                           Requests/Domains/
app/Policies/              Domain
app/Providers/             DomainServiceProvider
database/migrations/       tlds, tld_prices, domains, domain_events
resources/js/Pages/        Admin/Domains/{Index,Show}, Admin/Tlds/Index,
                           Client/Domains/{Index,Show}
resources/views/           storefront/domain-search
lang/{en,tr}/              domains.php
docs/adr/                  0028
```

## 6. Not done, and why

| Item | Detail |
| --- | --- |
| **Transfers end to end** | The contract, the adapter method, the pricing and the state are all built and tested. What is missing is the customer-facing flow: collecting the auth code, holding the order while the losing registrar takes five days, and the polling that notices it completed. That is Phase 9 automation work sitting on top of finished machinery. |
| **More registrars** | OpenSRS, Enom, ResellerClub and a generic EPP module plug into the finished contract. Writing them without an account to test against would produce adapters nobody has ever seen work — the same reason PayPal and the other control panels were deferred. |
| **Renewals and expiry dunning** | Phase 9. `expires_on` is recorded, `auto_renew` is a flag the registrar honours, `renewal_minor` holds what the customer was quoted, and `expiringWithin()` is the query the run will use. Nothing advances a date on its own yet. |
| **Scheduled sync** | `sync` exists and runs on demand. Putting it on a schedule belongs with the operations centre, which is where a drift between us and the registry should be interpreted rather than silently applied. |
| **IDN** | The column and the flag exist. Converting a Unicode name to punycode before it reaches `DomainName` is a small piece of work with a large test surface, and doing it badly produces names that look right and are not. |
| **Premium pricing** | The adapter reports `IsPremiumName`; the search shows it. Charging a registry-quoted premium rather than the matrix price needs a quote flow the matrix deliberately does not have. |
| **Domain-only checkout** | A domain can be bought alongside anything else. A storefront path that sells only domains, with its own landing page, is theme work for Phase 11. |

## 7. Carried risks

| Item | Detail |
| --- | --- |
| **The Namecheap adapter has never talked to Namecheap** | Its request shapes, error handling, XML parsing and idempotency are tested against faked HTTP, which proves the code and not the integration. Third time this warning has been written; it needs one run against the sandbox before an installation registers real names. |
| **The EPP code path is the least certain part of it** | Namecheap does not reliably return the code in `getInfo`. The adapter looks in both places it has been seen and fails honestly otherwise. This is the one method most likely to need changing after a first real run. |
| **Nothing reconciles us against the registry** | A domain transferred away or deleted directly at the registrar stays `active` here until somebody syncs it by hand. |
| **A registration is trusted without a second look** | When the adapter says it worked, the domain becomes active with an assumed expiry if the registrar did not report one. A sync corrects it. A registrar that lies, or a partial success, would leave a wrong date until then. |
| **`domains.name` is unique per organization, not globally** | Correct today, where one organization sells. If Phase 13 gives resellers their own domain business, two resellers could each hold a row for the same name, and only one of them would actually have it. |
| **Availability caching is per registrar and name, not per customer** | Two customers searching the same name within the minute see the same answer, which is correct, but one of them may act on a result the other has just bought. The unique index catches it; the second customer sees an error rather than a graceful message. |

## 8. Exact next recommended task

**Phase 8 — Support + Content + Notifications**, which is the last thing a
customer needs before this platform can run an installation unattended.

1. Tickets: departments, replies, internal notes, priority, assignment and
   SLA timers.
2. Announcements and a knowledge base, both themeable, both readable
   without signing in.
3. The notification system the previous four phases have been writing
   "Phase 8 will send this" about: email, in-app and webhook channels
   behind one contract, driven by the domain events
   ([ADR 0027](../adr/0027-contexts-meet-through-events.md)) that already
   exist.
