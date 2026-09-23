# Phase 7 — Domains Plan

Status: approved for implementation
Date: 2026-09-27
Scope: V2 roadmap Phase 7 — TLD pricing, domains as records with their own
lifecycle, the registrar contract and its first adapters, domain search on
the storefront, and the screens on both sides. Renewal invoicing and expiry
dunning are Phase 9; this phase records when a domain expires and leaves
the deciding to them.

---

## 1. Starting point

Phase 3 built the shape of a domain line and then deliberately left it
empty: `LineKind::Domain` exists, a cart line can carry a name, a TLD and a
term, and `PriceCart` prices one from an amount already sitting on the line.
Nothing puts a line there, because nothing knows what a `.com` costs.

The domain field on the configure screen is a different thing entirely — it
is the hostname a hosting account is set up for, and it belongs to the
product line. Both are called "domain" and they are not the same, which is
worth stating once before anything else here is built.

What this phase builds on:

- The **price matrix** ([ADR 0019](../adr/0019-price-matrix.md)): entered by
  hand, one row per combination, absence means not sold. TLD pricing is the
  same idea with different axes.
- **Provisioning's rules** ([ADR 0026](../adr/0026-provisioning-is-idempotent-and-failure-is-a-state.md)):
  idempotent operations, the external id written first, `already_done` as a
  success, failure as a state. A registration is the same kind of
  irreversible act as an account creation, and gets the same treatment.
- **`OrderPaid`** ([ADR 0027](../adr/0027-contexts-meet-through-events.md)).
  Domains subscribe to it alongside provisioning; neither knows the other
  exists.

## 2. The central decision: a domain is not a service

They look alike — both are bought, both expire, both have a provider behind
a contract — and merging them would save a table.

It would also be wrong in every detail that matters. A service is placed on
a node; a domain is not placed anywhere. A service is suspended and
unsuspended; a domain is locked, transferred and redeemed. A service's term
is a billing cycle; a domain's is a whole number of years, capped by the
registry. A service belongs to whoever bought it; a domain belongs to a
**registrant** whose details the registry holds, and who can be a different
person.

So: separate tables, a separate lifecycle, a separate contract. What they
share is the shape of how an adapter is called, and that shape is already
written down in ADR 0026.

## 3. The domain lifecycle

```text
pending ──> registering ──> active ──> expired ──> redemption ──> deleted
               │               │           │
               ├──> failed     ├──> cancelled
               │               └──> transferring_out
transfer_pending ──> transferring ──> active
```

| State | Meaning |
| --- | --- |
| `pending` | Bought, nothing sent to a registrar yet. |
| `registering` | A job is talking to the registrar now. |
| `transfer_pending` | Waiting for the customer to authorise, or for the losing registrar. |
| `transferring` | The transfer is running at the registry. |
| `active` | Registered and working. |
| `expired` | Past its date, still recoverable at the normal fee. |
| `redemption` | In the registry's redemption period, recoverable at a penalty. |
| `cancelled` | Never registered, or given up before it was. |
| `deleted` | Gone from the registry. Terminal. |
| `failed` | An operation failed and a human has to look. |

## 4. Tables

| Table | Notes |
| --- | --- |
| `tlds` | The extension (`com`, `co.uk`), registrar module, min/max years, whether transfer and whois privacy are offered, whether an EPP code is needed, IDN support, status |
| `tld_prices` | (tld, action, years, currency) → amount. Actions: register, renew, transfer, redeem. **A missing row means not sold**, exactly as in ADR 0019 |
| `domains` | Customer, order line, tld, name, registrar module, status, registered/expires/renews dates, auto-renew, registrar lock, whois privacy, nameservers, external id, last sync |
| `domain_events` | Append-only, the same shape as `service_events` |

Registrant contact details are **not** duplicated here. They are the
customer's contact record, sent to the registrar at registration; storing a
second copy would create two answers to "who owns this domain".

## 5. The registrar contract

```php
interface DomainRegistrar
{
    public function key(): string;
    public function capabilities(): RegistrarCapabilities;
    public function checkAvailability(DomainName $domain): AvailabilityResult;
    public function register(RegistrationRequest $request): RegistrarResult;
    public function transfer(TransferRequest $request): RegistrarResult;
    public function renew(DomainReference $domain, int $years): RegistrarResult;
    public function setNameservers(DomainReference $domain, array $nameservers): RegistrarResult;
    public function setLock(DomainReference $domain, bool $locked): RegistrarResult;
    public function setAutoRenew(DomainReference $domain, bool $enabled): RegistrarResult;
    public function requestTransferCode(DomainReference $domain): RegistrarResult;
    public function sync(DomainReference $domain): DomainSyncResult;
}
```

The four rules from ADR 0026 carry over unchanged, and one is added:

**An EPP transfer code is never stored.** It is fetched from the registrar
when a customer asks and shown once. It is the credential that moves a
domain away; a copy of it sitting in this database is a copy nobody needs.

Two adapters:

- **`ManualRegistrar`** — an operator registers it at the registrar's own
  panel and records the result. A real module, for the same reason the
  manual gateway and the manual provisioning module are.
- **`NamecheapRegistrar`** — the Namecheap API over HTTPS. XML responses,
  parsed defensively. Tested against faked HTTP, with the same honest
  warning as Stripe and cPanel: the code is proven, the integration is not.

## 6. Search and checkout

A visitor types a domain. The storefront:

1. Splits it into a name and a TLD it actually sells. A TLD with no price
   row in the current currency is not offered, and is not searched for.
2. Asks the registrar whether it is available, with a short cache — a
   registry answer is worth a minute, and a search box will ask the same
   question five times in that minute.
3. Offers what is free, and for what is taken offers a transfer if the TLD
   supports one.
4. Adds a `LineKind::Domain` line carrying the name, the TLD, the term and
   the price **read from the matrix at that moment** — the same copy rule
   as every other line ([ADR 0021](../adr/0021-order-lines-copy-the-catalog.md)).

An availability check that fails is not an error page. The domain is offered
as "we could not check" and the order is held for an operator, which is
better than telling a customer a name is free when nobody knows.

## 7. Fulfilment

```text
OrderPaid ──> RegisterDomainsForOrder ──> domain (pending)
                                            └──> RegisterDomain job
                                                    └──> adapter
                                                            ├──> active
                                                            └──> failed
```

Unique on the order line, like a service. The same three-valued result, the
same failure state, the same rule that the external id is written first.

## 8. Permissions added

| Permission | Notes |
| --- | --- |
| `domains.view` | |
| `domains.manage` | Nameservers, lock, auto-renew, contacts |
| `domains.register` | Run a registration, transfer or renewal |
| `catalog.tlds.view` | |
| `catalog.tlds.manage` | TLD pricing |
| `portal.domains.view` | Customer scope |
| `portal.domains.manage` | Nameservers, auto-renew, transfer code |

## 9. Screens

**Storefront.** A search box that answers with availability and a price, and
adds the result to the cart.

**Admin.** Domains: all, expiring, failed. Detail: the registrar, the dates,
nameservers, lock, auto-renew, the event log, sync. TLD pricing: the matrix,
with the same "the cell exists or it does not" control the product price
grid uses.

**Client.** My Domains, and a detail screen where a customer can change
nameservers, toggle auto-renew and ask for a transfer code — the three
things a domain owner actually does between registering and renewing.

## 10. Testing

- Availability: available, taken, and the registry being unreachable, which
  must not read as "available".
- Pricing: a TLD with no row in the requested currency is not sold; a term
  the registry will not accept is refused before a customer pays for it.
- Registration: success, `already_done` for a name this account already
  holds, failure leaving `failed` with a reason.
- Idempotency: the job twice, one registration.
- The EPP code is fetched, shown, and never written to the database.
- Isolation, as for every customer-facing screen.
- Money: a domain line's price equals the matrix row it was read from.

## 11. Order of work

1. Enums, tables, models, factories, permissions.
2. The contract and its value objects; the manual registrar; the fake.
3. TLD pricing, and the admin screen for it.
4. Search, availability caching, and the cart line.
5. `RegisterDomainsForOrder`, the jobs, the listener.
6. The Namecheap adapter.
7. Admin screens, then client screens.
8. Translations, ADR, result document.
