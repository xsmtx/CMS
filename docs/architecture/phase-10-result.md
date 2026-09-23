# Phase 10 — Public API and Developer Platform Result

Status: complete
Date: 2026-09-23
Plan: `phase-10-plan.md`
Next phase: Phase 11 (Theme / White-Label) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **862 passed, 3049 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 23 passed |
| Build | `vite build` | pass |
| Migrations | 22 migrations on MariaDB 11.8, rolled back and re-applied | clean |
| OpenAPI | `php artisan platform:openapi --check` | matches the routes |

Phase 9 finished at 817 tests; this phase adds 45, of which 6 are front-end.

## 2. The decisions this phase turns on

**The API is a surface, not a system**
([ADR 0033](../adr/0033-the-api-is-a-surface.md)).

A token resolves to an ordinary client actor: `AuthenticateApiToken` puts
its contact on the `client` guard and calls the same boundary middleware a
browser request uses. From there `CurrentActor`, `CurrentCustomer` and
every policy behave exactly as they do in the portal. A surface that
resolved identity differently would eventually authorize differently, and
the divergence always goes in the permissive direction.

A scope is a fourth question, asked after boundary, ownership and
permission, and it **can only narrow**. Each scope declares the permissions
behind it on the enum, so adding one forces the author to answer "and what
must the person be allowed to do" in the same edit.

**A write is replayable, and so is a delivery**
([ADR 0034](../adr/0034-a-write-is-replayable.md)).

Idempotency stores the *answer*, not just the key, and replays it verbatim.
Outbound, an endpoint's deliveries are rows with a stored payload and a
stable event id across attempts and redeliveries. The two halves are the
same sentence from opposite directions, and an integrator who understands
one understands the other.

## 3. Problems found

**A refused write burned the idempotency key.** The original rule kept any
response under 500, so a 422 was stored — and a client that corrected its
payload could then never retry, because the fixed body conflicted with the
fingerprint of the broken one. Found by the test named "it does not burn
the key when the write failed", written before the code was. The rule is
now: only a 2xx is remembered.

The same test exposed a second thing worth writing down. The rule had to
become a **status check** rather than a try/catch, because Laravel's routing
pipeline converts a validation failure into a 422 *response* before any
middleware sees it. A try/catch around `$next` would never fire for the
commonest refusal — exactly the case that matters.

**The activity log missed every refused request.** `RecordApiRequest`
wrapped `$next` and recorded afterwards, which works only when nothing is
thrown. An authentication failure *is* thrown, and the handler that turns
it into a 401 sits outside every route middleware. Moved to `terminate()`,
which runs with the final response whatever produced it — and the refused
requests are the ones most worth having.

**`created_at` was not fillable and strict mode said so.** A model with
`$timestamps = false` still needs the column in `$fillable`, and the record
was being silently swallowed by the middleware's own try/catch. Found by a
test asserting the row exists rather than by the code failing loudly, which
is the argument for asserting the row exists.

**A pre-existing token would have been handed the whole API.** Tokens
issued in Phase 5 carry `*`, which meant "everything" when there was no
API. Reading it as full access would have given every old token the entire
surface on upgrade day. It consented to nothing, so it gets nothing — and a
test says so.

**A `list<T>` that was not.** `Collection::pluck()->all()` is
`array<int, string>`, not `list<string>`. Third time this phase pattern has
appeared; `array_values(...)` remains the only thing that types.

## 4. What was built

### The surface

Twenty routes across profile, services, domains, invoices, orders, tickets
and webhooks. Reads paginate with a stable `data` / `meta` / `links` shape;
writes accept `Idempotency-Key`; actions that queue work return `202` with
`"status": "queued"`, because saying `200` would be a lie a client writes
code against.

Filters and sorts are declared per endpoint and **an undeclared one is a
422**. An integrator whose `?sort=nmae` is silently ignored gets an
unsorted list and no way to discover the typo.

Money is minor units and an ISO code everywhere, and a test walks every key
of a response asserting no float appears anywhere in it.

### Scopes and tokens

Twelve scopes, each declaring the portal permissions it requires. The
portal's token screen grew a grouped scope picker that shows a scope the
holder cannot grant as unavailable rather than hiding it — being told "you
cannot grant this" teaches something a missing row does not.

### Limits and activity

Per-token rate limits with a per-IP fallback, writes limited harder than
reads, and a 429 through the same error envelope with `Retry-After`. Every
request is recorded — who, what, when, what came back, and the correlation
id — and **never the body**, because a request body holds whatever the
client sent.

### Outbound webhooks

Endpoints with encrypted secrets, nineteen events, stored payloads, HMAC
signing over the exact bytes, bounded exponential retry driven by a row
rather than a delayed job, manual redelivery, and an endpoint that is
switched off after repeated failure rather than posted to forever.

Screens on both sides: the customer manages their own endpoints and sees
every delivery in the portal, with the verification recipe written out;
staff see all API activity in the admin panel.

### OpenAPI

`docs/api/openapi.json`, generated by `php artisan platform:openapi` from
the routes themselves, including the scope each route's middleware demands.
A test runs `--check` and fails if the committed copy has drifted. A
specification maintained by hand is wrong by the second release, and a
wrong one is worse than none.

### Admin navigation

Not in the plan, and done on request during the phase: the admin menu moved
from a sidebar to a top bar with WHMCS's groups, in WHMCS's order, with
WHMCS's words — Dashboard, Clients, Orders, Billing, Support, Utilities,
Setup — so that an operator arriving from WHMCS can find things on their
first day. Services and Domains keep their own menus, because a hosting
operator thinks about the machine and the name. Six component tests cover
the map, the ordering, opening, mutual exclusion and closing on navigate.

## 5. Files

```text
app/Domain/Api/            ApiScope, WebhookEvent, DeliveryState
app/Application/Api/       DispatchWebhooks, DeliverWebhookNow
app/Application/Automation/Runs/RetryWebhookDeliveries
app/Infrastructure/Api/    Models/{WebhookEndpoint,WebhookDelivery,
                           ApiRequestRecord,IdempotencyRecord},
                           Jobs/DeliverWebhook
app/Http/Api/              QueryOptions, ApiResource
app/Http/Controllers/Api/V1/
                           ApiController, Profile, Service, Domain,
                           Invoice, Order, Ticket, WebhookEndpoint
app/Http/Controllers/Admin/ApiActivityController
app/Http/Controllers/Client/WebhookController
app/Http/Middleware/       AuthenticateApiToken, RequireApiScope,
                           EnforceIdempotency, RecordApiRequest
app/Http/Requests/Api/     Ticket, TicketReply, Nameserver, WebhookEndpoint
app/Support/Errors/        UnauthenticatedException,
                           ValidationFailedException,
                           IdempotencyConflictException
app/Console/Commands/      GenerateOpenApiCommand
app/Providers/             ApiServiceProvider
database/migrations/       api and webhook tables (4)
resources/js/Pages/        Admin/Api/Activity,
                           Client/Developer/{Tokens,Webhooks}
resources/js/Layouts/      AdminLayout (top navigation), AdminLayout.test.ts
lang/{en,tr}/              api.php
docs/adr/                  0033, 0034
docs/api/                  README.md, openapi.json
```

## 6. Not done, and why

| Item | Detail |
| --- | --- |
| **Placing an order** | Needs a cart, an agreed price, a tax decision and a risk decision — the whole checkout, which is a surface of its own rather than one endpoint. Reading orders is what an integration asks for first anyway: reconciling what was bought. |
| **Paying an invoice** | Money, from a credential nobody types. It gets its own scope and its own argument about what a failed charge on an unattended script should do. |
| **Terminating a service** | Destroying an account while its owner is asleep is not something a token should be able to do. An integration that genuinely needs it can ask, and get its own decision. |
| **Any admin API** | Staff tokens need their own scope set, their own rate limits and their own audit story. Shipping half an admin API is worse than shipping none. |
| **OAuth2 / OIDC** | Sanctum first, as the handoff says. The contracts are clean enough to add an authorization-code flow later without moving the scope model. |
| **Per-endpoint webhook filtering beyond events** | An endpoint subscribes to event types, not to "invoices over €100". A filter language is a product of its own. |
| **A dead-letter queue and alerting** | An operator finds out that an endpoint was disabled by looking. Pushing that to email or a pager is Phase 12's work, and `Notifier` is already the voice it will use. |
| **Response caching and ETags** | Nothing here is expensive enough yet to be worth the invalidation bugs. |

## 7. Carried risks

| Item | Detail |
| --- | --- |
| **No real integration has used this** | Every endpoint is proven against a test client in the same process. Nothing has been through a proxy, a corporate TLS interceptor, or a client library that sends `Expect: 100-continue`. |
| **The rate limits are a guess** | 120 reads and 30 writes a minute per token. Nobody has run an integration against them, and the first real client may find them either pointless or hostile. |
| **A webhook endpoint is trusted with what it is sent** | Validation refuses non-HTTPS, but a customer can still point an endpoint at an internal address and have the platform post their own data there. Full SSRF protection means resolving at delivery time and refusing private ranges, which is a change to `DeliverWebhookNow` rather than to validation. |
| **Idempotency records hold response bodies** | Which is customer data, kept for 24 hours by default. The cleanup task deletes them; an installation that lowers the retention to zero loses the guarantee. |
| **`terminate()` records the request after the response is sent** | A worker killed between the two loses that row. Acceptable for a log; it would not be for anything anybody bills from. |
| **The OpenAPI document describes shapes loosely** | Paths, methods, parameters, scopes and the error envelope are exact. Response bodies are described as "Success" rather than as schemas, so a client generator produces callable methods and untyped results. |

## 8. Exact next recommended task

**Phase 11 — Theme and white-label.**

1. Storefront, client and reseller theme manifests, with child themes and
   upgrade-safe overrides.
2. Branding per organization — the thing every reseller asks for first —
   over the `StorefrontRenderer` abstraction Phase 2 left for exactly this.
3. The asset pipeline question: a theme that ships its own CSS needs a
   build story that does not require the operator to run `npm`.

The pieces this phase leaves ready: nothing in the API assumes a theme, and
`docs/api/README.md` is the first customer-facing document that will need
to carry a brand name rather than "InfraCMS".
