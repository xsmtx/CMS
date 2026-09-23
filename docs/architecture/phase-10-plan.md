# Phase 10 — Public API and Developer Platform Plan

Status: approved for implementation
Date: 2026-09-23
Scope: V2 roadmap Phase 10 — `/api/v1` over the existing use cases, token
scopes, per-token rate limits, idempotency keys on writes, pagination and
filtering, an API activity log, outbound webhook endpoints with signing and
redelivery, and an OpenAPI document generated from the routes.

---

## 1. Starting point

More of this phase already exists than is obvious.

- **The error envelope is built** (`docs/api/errors.md`, `ErrorCode`,
  `ApiExceptionRenderer`). Every code an API needs is already a member,
  including `idempotency_key_conflict`, which was added in Phase 0 for
  exactly this phase.
- **Correlation identifiers already travel** into every response and every
  queued job, and `request_id` is already in the envelope.
- **Tokens already exist.** A customer can issue and revoke them from the
  portal; they carry `*` and reach nothing, because the API they would
  reach did not exist. `abilities` is a column waiting for scopes.
- **Every rule is already in an application use case.** `OpenTicket`,
  `ReplyToTicket`, `RunServiceOperation`, `RunDomainOperation`,
  `AddToCart` — the API calls what the screens call, or the phase has
  failed.
- **Outbound signing is solved.** `WebhookChannel` already signs with
  HMAC-SHA256 over the exact bytes plus a timestamp, the same way incoming
  gateway webhooks are verified. What is missing is endpoints an operator
  can manage, delivery records, and redelivery.

What is missing is the surface, and the four things that make a surface
safe to expose: scopes, limits, replayable writes and a record of what was
asked.

## 2. The central decision: the API is a surface, not a system

The tempting shape is an `Api` namespace with its own controllers, its own
queries and — within two phases — its own slightly different rules. It
starts as "the API needs a lighter version of this" and ends with a client
whose ticket priority means something different from the portal's.

**The API calls the same application use cases the screens call.** A
controller under `Api/V1` validates, authorizes, calls a use case and
renders a resource. It contains no rule that a portal controller does not,
and where the portal has a rule the API does not, that is a bug in one of
them.

**Authorization stays three questions, and a scope is a fourth.** Boundary,
ownership, permission — as everywhere else — and then the token's scope.
The order matters and so does the direction: **a scope can only narrow.** A
token with `services:write` held by a contact without
`portal.services.view` reaches nothing. A scope is what the token's holder
allowed this integration to do, not what the platform allows the account to
do, and confusing the two is how an API becomes a privilege-escalation
path.

**The token identifies a contact, not an account.** It is issued by a
person, it inherits their permissions, and revoking their access revokes
the token's. A token that outlives its holder's employment is the thing
every incident report is about.

ADR 0033 records this.

## 3. The second decision: a write is replayable, and so is a delivery

Two problems that look unrelated and are the same problem.

**Inbound: a client that did not hear the answer.** A POST that creates an
order times out at a proxy. The client does not know whether the order
exists. It retries, and now there are two. Every serious API solves this
with an idempotency key, and the half-solution — "we deduplicate on a
natural key" — fails the moment two legitimately identical requests are
both correct.

So: a write route accepts `Idempotency-Key`. The first request stores the
key, a fingerprint of the request, and the response it produced. A repeat
with the same key and the same fingerprint **returns the stored response**,
including its status code, without running anything. A repeat with the same
key and a *different* fingerprint is `idempotency_key_conflict` — the
client has a bug, and quietly returning the first answer would hide it.

**Outbound: an endpoint that did not hear us.** An operator's system was
down when `invoice.paid` fired. The event is gone unless something kept it.

So: `webhook_endpoints` are rows with a secret, a set of subscribed events
and an active flag; every attempt is a `webhook_deliveries` row with its
status code, its response body's first kilobyte and its attempt number;
failures retry with the bounded exponential backoff Phase 9 already uses;
and an operator can **redeliver** any delivery by hand. The payload carries
a stable event id, so a receiver can deduplicate exactly as this platform
asks its own clients to.

Both are the same statement: **the thing that happened is a record, and the
record can be replayed.** It is the third time this platform has reached
for it — the ledger, the operations centre, and now this.

ADR 0034 records it.

## 4. Scopes

Read and write per resource group, matching the handoff:

```text
profile:read    profile:write
services:read   services:write
domains:read    domains:write
invoices:read
orders:read     orders:write
tickets:read    tickets:write
webhooks:read   webhooks:write
```

Four rules, each of which exists because its absence is a bug class:

1. **`:write` does not imply `:read`.** A token that may open a ticket but
   not read the others is a real integration, and the reverse is the more
   common one.
2. **A scope maps to permissions, never replaces them.** Each scope
   declares the portal permissions it requires; the token holder must have
   all of them.
3. **`invoices` is read-only in v1.** Paying an invoice moves money, and a
   token is a password nobody types. When that changes it will be its own
   decision with its own scope.
4. **No admin scopes in v1.** Staff tokens need their own scope set, their
   own rate limits and their own audit story. The customer-facing API is
   what an integrator actually needs first, and shipping half an admin API
   is worse than shipping none.

## 5. What gets built

### The surface

| Group | Routes |
| --- | --- |
| profile | `GET /profile` |
| services | `GET /services`, `GET /services/{id}`, `POST /services/{id}/actions/{action}` |
| domains | `GET /domains`, `GET /domains/{id}`, `POST /domains/{id}/nameservers`, `POST /domains/{id}/renew` |
| invoices | `GET /invoices`, `GET /invoices/{id}` |
| orders | `GET /orders`, `GET /orders/{id}` |
| tickets | `GET /tickets`, `GET /tickets/{id}`, `POST /tickets`, `POST /tickets/{id}/replies` |
| webhooks | `GET /webhooks`, `POST /webhooks`, `DELETE /webhooks/{id}`, `GET /webhooks/{id}/deliveries`, `POST /webhooks/deliveries/{id}/redeliver` |

Every collection paginates with a stable cursor-free shape (`data`, `meta`,
`links`), filters on a small declared set of fields, and sorts on a small
declared set. **Undeclared filters and sorts are a validation error, not
silently ignored** — an integrator whose `?sort=nmae` is ignored gets an
unsorted list and no way to know why.

Money is rendered as it is stored: minor units and an ISO code, never a
float and never a formatted string. A client that wants "€14.99" formats it
itself, in its own locale.

### Tokens with scopes

The portal's token screen grows a scope picker. Scopes are shown grouped,
with a sentence each, and the token is still shown exactly once. An expiry
is offered and defaulted to something finite, because a token with no
expiry is a credential that outlives the reason it was issued.

`last_used_at` is already a Sanctum column and is surfaced, because "is
this token still in use" is the question before revoking one.

### Limits and activity

Per-token rate limits with a per-IP fallback for unauthenticated requests,
`X-RateLimit-*` headers on every response, and `Retry-After` on a 429.

Every API request writes an `api_requests` row: token, route, method,
status, duration, IP, correlation id. Not the body — a request body holds
whatever the client sent, including a ticket message about a password.

### Outbound webhooks

Endpoints, subscriptions, deliveries, signing, retry, redelivery, and the
admin and portal screens for them.

### OpenAPI

Generated from the routes and the resource classes by a console command,
written to `docs/api/openapi.json`, and **checked in CI**: a test
regenerates it and fails if the committed copy differs. A specification
maintained by hand is a specification that is wrong by the second release.

## 6. Order of work

1. Migrations: `api_requests`, `idempotency_keys`, `webhook_endpoints`,
   `webhook_deliveries`.
2. Scopes, the token guard middleware, and the portal screen's scope picker.
3. The read surface: profile, services, domains, invoices, orders, tickets,
   with pagination, filtering and sorting.
4. Idempotency middleware, then the write surface.
5. Rate limits and the activity log.
6. Outbound webhooks: endpoints, dispatch, retry, redelivery, screens.
7. OpenAPI generation and its CI test.
8. Permissions, translations, ADRs 0033 and 0034, `docs/api/` guide,
   result document.

## 7. Definition of done for this phase

Everything in the standing list, plus:

- Every route is proven to refuse a token whose scope does not cover it,
  **and** a token whose holder lacks the permission behind that scope.
- Every write route is proven to return the same response for a repeated
  idempotency key, and to conflict on a changed payload under the same key.
- A collection is proven to refuse an undeclared filter and an undeclared
  sort.
- No response contains a monetary float, and a test asserts it.
- The committed OpenAPI document matches what the routes generate.
- A webhook delivery is proven to be signed, retried and redeliverable.
